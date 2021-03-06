<?php

namespace App\Services\Synchronizers;

use Illuminate\Support\Str;
use App\Credential;
use App\Issue;
use App\IssueComment;
use App\IssueFile;
use App\IssueTracker\AccessException;
use App\Mirror;
use App\Project;
use IssueLabelsMapper;
use RedmineCommentsCreator;
use App\User;
use App\Log;
use App\Milestone;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log as FacadesLog;
use Illuminate\Support\Facades\Storage;

class LocalRedmineSynchronizer {

    /**
     * @var \Redmine\Client
     */
    protected $client;

    /**
     * @var App\Server
     */
    protected $server;

    /**
     * @var App\Mirror
     */
    protected $mirror;

    /**
     * @var App\Log
     */
    protected $log;

    /**
     *
     * @param App\Server $server
     */
    public function __construct($server)
    {
        $this->server = $server;
    }

    /**
     * Создает клиент подключения к Redmine и присаивает его инстанс атрибуту $client
     *
     * @param string|null $apiKey
     * @return void
     */
    protected function connect(?string $apiKey = null): void
    {
        if (!$apiKey) {
            $apiKey = $this->mirror->owner->credentials()->where('server_id', $this->server->id)->first()->api_key;
        }
        $this->client = new \Redmine\Client($this->server->base_uri, $apiKey);
    }

    /**
     * Присваивает атрибуту mirror инстанс объекта типа App\Mirror
     *
     * @param Mirror $mirror
     * @return void
     */
    protected function setMirror(Mirror $mirror): void
    {
        $this->mirror = $mirror;
    }

    /**
     * Создает запись лога в БД
     *
     * @param string $type
     * @return void
     */
    protected function createLog(string $type)
    {
        $this->log = Log::create([
            'mirror_id' => $this->mirror->id,
            'type' => $type,
            'status' => 'In process'
        ]);
    }

    /**
     * Сохраняет все новые задачи и изменения по задачам локально в БД
     *
     * @param Project $project Проект из которого "тянуть" задачи
     * @param Mirror $mirror "Заркало" к которому относится проект
     * @param Carbon|null $issuesUpdatedAtDate Дата и время начиная с которой запрашивать обновления
     * @param Carbon|null $issuesCreatedAtDate Дата и время начиная с которой запрашивать созданные задачи
     * @return void
     */
    public function pullIssues(
        Project $project, 
        Mirror $mirror, 
        ?Carbon $issuesUpdatedAtDate, 
        ?Carbon $issuesCreatedAtDate
        ): void
    {
        $this->setMirror($mirror);
        $this->createLog('Pull issues');
        $this->connect();
        $issues = $this->getIssues($project, $issuesUpdatedAtDate, $issuesCreatedAtDate);
        foreach ($issues as $issue) {
            try {
                $this->updateOrCreateLocalIssue($issue, $project);
            } catch (\Throwable $th) {
                $message = "Error pulling to {$project->name} an issue \"{$issue['subject']}\": {$th->getMessage()}";
                dump($message);
                FacadesLog::error($message);
                $this->log->errors()->create([
                    'message' => $message
                ]);
            }
        }
        if (count($this->log->errors)) {
            $this->log->status = 'Finished with errors';
        } else {
            $this->log->status = 'Success';
        }
        $this->log->save();
    }

    /**
     * Отправляет все новые задачи и изменения в "зеркальный проект"
     *
     * @param Collection|Issue[] $issuesToPush Коллекция задач для отправки
     * @param Project $project Проект куда отправляются задачи
     * @param Mirror $mirror "Заркало" к которому относится проект
     * @return void
     */
    public function pushIssues(Collection $issuesToPush, Project $project, Mirror $mirror): void
    {
        $this->setMirror($mirror);
        $this->createLog('Push issues');
        foreach ($issuesToPush as $localIssue) {
            try {
                if ($credential = $localIssue->author->credentials()->where('server_id', $this->server->id)->first()) {
                    $this->connect($credential->api_key);
                } else {
                    $this->connect();
                }

                $remoteIssue = $this->updateOrCreateRemoteIssue($localIssue, $project);

                foreach ($localIssue->commentsToPush($project->id)->get() as $comment) {
                    $this->pushComment($comment, $project, $remoteIssue['id']);
                }

                foreach ($localIssue->filesToPush($project->id)->get() as $file) {
                    $this->pushFile($file, $project, $remoteIssue['id']);
                }

            } catch (\Throwable $th) {
                $message = "Error pushing to {$project->name} an issue \"{$localIssue->subject}\": {$th->getMessage()}";
                dump($message);
                FacadesLog::error($message);
                $this->log->errors()->create([
                    'message' => $message
                ]);
            }
        }

        if (count($this->log->errors)) {
            $this->log->status = 'Finished with errors';
        } else {
            $this->log->status = 'Success';
        }
        $this->log->save();
    }

    /**
     * Возвращает массив задач в соответствии с фильтрами
     *
     * @param Project $project
     * @param Carbon|null $issuesUpdatedAtDate
     * @param Carbon|null $issuesCreatedAtDate
     * @return array
     */
    protected function getIssues(Project $project, ?Carbon $issuesUpdatedAtDate, ?Carbon $issuesCreatedAtDate): array
    {
        $offset = 0;
        $totalCount = 1;
        $issues = [];
        while ($totalCount > count($issues)) {
            $params = [
                'offset' => $offset,
                'project_id' => $project->ext_id,
                'status_id' => '*',
            ];

            if ($issuesUpdatedAtDate) {
                $params['updated_on'] = ">={$issuesUpdatedAtDate->toIso8601ZuluString()}";
            }
            if ($issuesCreatedAtDate) {
                $params['created_on'] = ">={$issuesCreatedAtDate->toIso8601ZuluString()}";
            }

            $response = $this->client->issue->all($params);
            $offset += $response['limit'];
            $totalCount = $response['total_count'];
            $issues = array_merge($issues, $response['issues']);
        }
        return $issues;
    }

    /**
     * Обновляет или создает зажачу локально в БД
     *
     * @param array $issue Массив с атрибутами задачи из Redmine
     * @param Project $project Проект, к которому относится задача
     * @return void
     */
    protected function updateOrCreateLocalIssue(array $issue, Project $project): void
    {
        $localIssue = (new Issue)->queryByRemote($issue['id'], $project->id)->first();
        if ($localIssue && $localIssue->updated_at->lessThan(Carbon::parse($issue['updated_on']))) {
            $localIssue = $this->updateLocalIssue($issue, $localIssue);
            $localIssue->syncedIssues()->where('project_id', $project->id)->update([
                'updated_at' => $localIssue->updated_at
            ]);
            $this->attachLabels($localIssue, $issue, $project);
        } else if (!$localIssue) {
            $localIssue = $this->createLocalIssue($issue, $project);
            $localIssue->syncedIssues()->create([
                'project_id' => $localIssue->project->id,
                'ext_id' => $localIssue->ext_id,
                'updated_at' => $localIssue->updated_at,
                'created_at' => $localIssue->created_at
            ]);
            $this->attachLabels($localIssue, $issue, $project);
        }
        $this->addComments($issue, $localIssue, $project);
        $this->addFiles($issue, $localIssue, $project);
    }

    /**
     * Сопоставлят лейблы в соответствии с правилами в App\Mirror (ltr_labels, rtl_labels)
     *
     * @param Issue $localIssue Локальная задача в БД
     * @param array $issue Массив с атрибутами задачи из Redmine
     * @param Project $project Проект, к которому относится задача
     * @return void
     */
    protected function attachLabels(Issue $localIssue, array $issue, Project $project): void
    {
        $types = [
            'status',
            'tracker',
            'priority'
        ];
        if ($localIssue->ext_id === $issue['id']) {
            foreach ($types as $type) {
                $label = IssueLabelsMapper::getLabelByExtId($issue[$type]['id'], $this->server->id, $type);
                if ($label)
                {
                    $this->attachOneLabel($localIssue, $issue, $type, $label->id);
                }
            }
        } else {
            $labelsMap = $this->mirror->getLabelsMap($project);
            foreach ($types as $type) {
                $labelId = IssueLabelsMapper::findIdInLabels($localIssue->$type()->id, $this->mirror->getMirrorLabelsMap($project));
                if ($labelId) {
                    $label = IssueLabelsMapper::getLabelByExtId($issue[$type]['id'], $this->server->id, $type);
                    if ($labelId = IssueLabelsMapper::findIdInLabels($label->id, $labelsMap)) {
                        $this->attachOneLabel($localIssue, $issue, $type, $labelId);
                    }  else {
                        $message = 'Cannot attach label. Not matched label: ' . $issue[$type]['name'];
                        dump($message);
                        FacadesLog::error($message);
                        $this->log->errors()->create([
                            'message' => $message
                        ]);
                    }
                } else {
                    $message = 'Cannot attach label. Not matched label: ' . $localIssue->$type()->name;
                    dump($message);
                    FacadesLog::error($message);
                    $this->log->errors()->create([
                        'message' => $message
                    ]);
                }
            }
        }
    }

    /**
     * Сопоставлят лейбл в соответствии с правилами в App\Mirror (ltr_labels, rtl_labels)
     *
     * @param Issue $localIssue
     * @param array $issue
     * @param string $type
     * @param integer $labelId
     * @return void
     */
    protected function attachOneLabel(Issue $localIssue, array $issue, string $type, int $labelId): void
    {
        if (!$localIssue->$type() || $localIssue->$type()->id !== $labelId) {
            if ($localIssue->$type()) {
                $localIssue->enumerations()->detach($localIssue->$type()->id);
            }
            $localIssue->enumerations()->attach($labelId);
            $localIssue->withoutEvents(function () use ($localIssue, $issue) {
                $localIssue->update([
                    'updated_at' => Carbon::parse($issue['updated_on'])->setTimezone(config('app.timezone'))
                ]);
            });
        }
    }

    /**
     * Обновляет или создает зажачу локально в Redmine
     *
     * @param Issue $localIssue
     * @param Project $project
     * @return array
     */
    protected function updateOrCreateRemoteIssue(Issue $localIssue, Project $project): array
    {
        $syncedIssue = $project->syncedIssues()->where('issue_id', $localIssue->id)->first();
        $assigne = $localIssue->assignee 
            ? $project->server->credentials()->where('user_id', $localIssue->assignee->id)->first() 
            : null;
        $milestone = $this->mirror->getMilestone($project);
        $attributes = [
            'subject' => $localIssue->subject,
            'description' => $localIssue->description,
            'project_id' => $project->ext_id,
            'fixed_version_id' => $milestone ? $milestone->ext_id : null,
            'assigned_to_id' => $assigne['ext_id'] ?? $this->mirror->owner->credentials()->where('server_id', $project->server_id)->first()->ext_id,
            'estimated_hours' => $localIssue->estimated_hours,
            'done_ratio' => $localIssue->done_ratio,
            'start_date' => $localIssue->started_at ? $localIssue->started_at->toDateString() : null,
            'due_date' => $localIssue->finished_at ? $localIssue->finished_at->toDateString() : null,
            'author_id' => $this->getAccount()['id']
        ];

        if (!$syncedIssue || $syncedIssue->ext_id !== $localIssue->ext_id) {
            $attributes['custom_fields']= [
                ['id' => 27, 'value' => $localIssue->project->server->base_uri . 'issues/' . $localIssue->ext_id]
            ];
        }

        if ($syncedIssue && $syncedIssue->ext_id === $localIssue->ext_id) {
            $attributes['tracker_id'] = $localIssue->tracker()->ext_id;
            $attributes['status_id'] = $localIssue->status()->ext_id;
            $attributes['priority_id'] = $localIssue->priority()->ext_id;
        } else if (!$syncedIssue) {
            $labelsMap = $this->mirror->getMirrorLabelsMap($project);
            if ($labelsMap) {
                if ($ext_id = IssueLabelsMapper::getLabelExtId($localIssue, $labelsMap, 'tracker')) {
                    $attributes['tracker_id'] = $ext_id;
                } else {
                    $message = 'Cannot update remote label. Not matched label: ' . $localIssue->tracker()->name;
                    dump($message);
                    FacadesLog::error($message);
                    $this->log->errors()->create([
                        'message' => $message
                    ]);
                }
                if ($ext_id = IssueLabelsMapper::getLabelExtId($localIssue, $labelsMap, 'status')) {
                    $attributes['status_id'] = $ext_id;
                } else {
                    $message = 'Cannot update remote label. Not matched label: ' . $localIssue->status()->name;
                    dump($message);
                    FacadesLog::error($message);
                    $this->log->errors()->create([
                        'message' => $message
                    ]);
                }
                if ($ext_id = IssueLabelsMapper::getLabelExtId($localIssue, $labelsMap, 'priority')) {
                    $attributes['priority_id'] = $ext_id;
                } else {
                    $message = 'Cannot update remote label. Not matched label: ' . $localIssue->priority()->name;
                    dump($message);
                    FacadesLog::error($message);
                    $this->log->errors()->create([
                        'message' => $message
                    ]);
                }
            }
        }

        if ($syncedIssue) {
            $response = $this->updateRemoteIssue($syncedIssue->ext_id, $attributes);
            $syncedIssue->update([
                'updated_at' => Carbon::parse($response['updated_on'])->setTimezone(config('app.timezone'))
            ]);
        } else {
            $response = $this->createRemoteIssue($attributes);
            $localIssue->syncedIssues()->create([
                'project_id' => $project->id,
                'ext_id' => $response['id'],
                'updated_at' => Carbon::parse($response['updated_on'])->setTimezone(config('app.timezone'))
            ]);
        }
        return $response;
    }

    /**
     * Обновляет задачу в Redmine
     *
     * @param integer $id
     * @param array $attributes
     * @return array
     */
    protected function updateRemoteIssue(int $id, array $attributes)
    {
        $this->client->issue->update($id, $attributes);
        return $this->client->issue->show($id)['issue'];
    }

    /**
     * Создает задачу в Redmine
     *
     * @param array $attributes
     * @return void
     */
    protected function createRemoteIssue(array $attributes)
    {
        return (array)$this->client->issue->create($attributes);
    }

    /**
     * Обновляет задачу локально в БД
     *
     * @param array $issue
     * @param Issue $localIssue
     * @return Issue
     */
    protected function updateLocalIssue(array $issue, Issue $localIssue): Issue
    {
        $assignee = isset($issue['assigned_to']) ? $this->getUser($issue['assigned_to']['id']) : null;
        if (isset($issue['fixed_version'])) {
            $milestone = Milestone::where([
                'ext_id' => $issue['fixed_version']['id'],
                'project_id' => $localIssue->project_id
            ])->first();
        }
        $localIssue->update([
            'subject' => $issue['subject'],
            'milestone_id' => $milestone->id ?? null,
            'started_at' => isset($issue['start_date']) 
                ? Carbon::parse($issue['start_date'])->setTimezone(config('app.timezone'))
                : null,
            'finished_at' => isset($issue['due_date']) 
                ? Carbon::parse($issue['due_date'])->setTimezone(config('app.timezone')) 
                : null,
            'assignee_id' => $assignee ? $assignee->id : $this->mirror->owner_id,
            'estimated_hours' => $issue['estimated_hours'] ?? null,
            'done_ratio' => $issue['done_ratio'] ?? null,
            'description' => $issue['description'] ?? null,
            'updated_at' => Carbon::parse($issue['updated_on'])->setTimezone(config('app.timezone'))
        ]);
        return $localIssue;
    }

    /**
     * Создает задачу локально в БД
     *
     * @param array $issue
     * @param Project $project
     * @return Issue
     */
    protected function createLocalIssue(array $issue, Project $project): Issue
    {
        $assignee = isset($issue['assigned_to']) ? $this->getUser($issue['assigned_to']['id']) : null;
        $author = $this->getUser($issue['author']['id']);
        if (isset($issue['fixed_version'])) {
            $milestone = Milestone::where([
                'ext_id' => $issue['fixed_version']['id'],
                'project_id' => $project->id
            ])->first();
        }
        return Issue::create([
            'ext_id' => $issue['id'],
            'milestone_id' => $milestone->id ?? null,
            'project_id' => $project->id,
            'author_id' => $author['id'],
            'assignee_id' => $assignee ? $assignee->id : $this->mirror->owner_id,
            'subject' => $issue['subject'],
            'estimated_hours' => $issue['estimated_hours'] ?? null,
            'done_ratio' => $issue['done_ratio'] ?? null,
            'description' => $issue['description'] ?? null,
            'started_at' => isset($issue['start_date']) 
                ? Carbon::parse($issue['start_date'])->setTimezone(config('app.timezone'))
                : null,
            'finished_at' => isset($issue['due_date']) 
                ? Carbon::parse($issue['due_date'])->setTimezone(config('app.timezone')) 
                : null,
            'updated_at' => Carbon::parse($issue['updated_on'])->setTimezone(config('app.timezone'))
        ]);
    }

    /**
     * Получает пользователя из Redmine по ИД и сопоставляет его с локальным пользователем
     *
     * @param integer $id
     * @return User
     */
    protected function getUser(int $id): User
    {
        $user = $this->client->user->show($id)['user'];
        return $this->updateOrCreateUser($user);
    }

    /**
     * Возвращает массив с атрибутами текущего пользователя Redmine (под которым создано подключение)
     *
     * @return array
     */
    protected function getAccount(): array
    {
        $response = $this->client->user->getCurrentUser();
        if (isset($response['user'])) {
            return $response['user'];
        } else {
            throw new AccessException("Unauthorized", 403);
        }
    }

    /**
     * Создает пользователя Redmine локально в БД или обновляет его атрибуты
     *
     * @param array $user
     * @return User
     */
    protected function updateOrCreateUser(array $user): User
    {
        $credential = Credential::where([
            'ext_id' => $user['id'],
            'server_id' => $this->server->id
        ])->first();

        if (!$credential) {
            $credential = Credential::create([
                'username' => $user['login'] ?? $user['firstname'] . ' ' . ($user['lastname'] ?? ''),
                'server_id' => $this->server->id,
                'ext_id' => $user['id']
            ]);
            
            if (isset($user['mail'])) {
                $localUser = User::where('email', $user['mail'])->orWhere('name', $user['firstname'] . ' ' . ($user['lastname'] ?? ''))->first();
            } else {
                $localUser = User::where('name', $user['firstname'] . ' ' . ($user['lastname'] ?? ''))->first();
            }

            if (!$localUser) {
                $localUser = User::create([
                    'email' => $user['mail'] ?? null,
                    'name' => $user['firstname'] . ' ' . ($user['lastname'] ?? ''),
                    'password' => Str::random(64)
                ]);
            } else if (isset($user['mail'])) {
                $localUser->update([
                    'email' => $user['mail']
                ]);
            }
            
            $credential->user_id = $localUser->id;
            $credential->save();
        }
        return $credential->user;
    }

    /**
     * Возвращает массив всех комментариев по задаче из Redmine (в том числе и изменение статусов)
     *
     * @param integer $id
     * @return array
     */
    protected function getComments(int $id): array
    {
        $comments = [];
        $journals = $this->client->issue->show($id, ['include' => 'journals'])['issue']['journals'];
        foreach ($journals as $item) {
            if ($item['notes']) {
                $item['user'] = $this->getUser($item['user']['id']);
                $comments[] =$item;
            }
            $customNotes = RedmineCommentsCreator::createFromJournalDetails($item['details'], $this->server->id);
            if ($customNotes) {
                if (!$item['user'] instanceof \App\User) {
                    $item['user'] = $this->getUser($item['user']['id']);
                }
                if ($item['notes']) {
                    $comments[count($comments) - 1]['notes'] = $customNotes . $comments[count($comments) - 1]['notes'];
                } else {
                    $item['notes'] = $customNotes;
                    $comments[] = $item;
                }
            } 
        }
        return $comments;
    }

    /**
     * Создает комментарий по задаче локально в БД
     *
     * @param array $issue
     * @param Issue $localIssue
     * @param Project $project
     * @return void
     */
    protected function addComments(array $issue, Issue $localIssue, Project $project): void
    {
        $comments = $this->getComments($issue['id']);
        foreach ($comments as $comment) {
            if (!(new IssueComment)->queryByExternalId($comment['id'])->first()) {
                $localComment = $localIssue->comments()->create([
                    'body' => $comment['notes'],
                    'ext_id' => $comment['id'],
                    'author_id' => $comment['user']->id,
                    'created_at' => $comment['created_on']
                ]);
                $localComment->syncedComments()->create([
                    'ext_id' => $comment['id'],
                    'project_id' => $project->id
                ]);
            }
        }
    }

    /**
     * Отправляет комментарий в Redmine
     *
     * @param IssueComment $comment
     * @param Project $project
     * @param integer $issueId
     * @return void
     */
    protected function pushComment(IssueComment $comment, Project $project, int $issueId): void
    {
        if ($credential = $comment->author->credentials()->where('server_id', $this->server->id)->first()) {
            $this->connect($credential->api_key);
        } else {
            $this->connect();
            $comment->body = $comment->body .   "\nАвтор комментария: " . $comment->author->name;
        }

        try {
            $this->updateRemoteIssue($issueId, ['notes' => $comment->body]);
            $comments = $this->getComments($issueId);
            $comment->syncedComments()->create([
                'ext_id' => end($comments)['id'],
                'project_id' => $project->id
            ]);
        } catch (\Throwable $th) {
            dump($th->getMessage());
        }
    }

    /**
     * Возвращает массив прикрепленных к задаче файлов
     *
     * @param integer $id
     * @return array
     */
    protected function getFiles(int $id): array
    {
        $files = [];
        $attachments = (array)$this->client->issue->show((string)$id, ['include' => 'attachments'])['issue']['attachments'];
        foreach ($attachments as $item) {
            $item['content'] = $this->client->attachment->download($item['id']);
            $item['author'] = $this->getUser($item['author']['id']);
            $files[] = $item;
        }
        return $files;
    }

    /**
     * Сохраняет файлы из Redmine локально на диск и в БД
     *
     * @param array $issue
     * @param Issue $localIssue
     * @param Project $project
     * @return void
     */
    protected function addFiles(array $issue, Issue $localIssue, Project $project): void
    {
        $files = $this->getFiles($issue['id']);
        foreach ($files as $file) {
            $localfile = (new IssueFile)->queryByExternalId($file['id'])->first();
            if ($localfile) {
                $localfile->update([
                    'name' => $file['filename'],
                    'description' => $file['description']
                ]);
            } else {
                $path = 'files/' . uniqid(). $file['filename'];
                Storage::disk('local')->put($path, $file['content']);
                $localFile = $localIssue->files()->create([
                    'name' => $file['filename'],
                    'description' => $file['description'],
                    'path' => $path,
                    'ext_id' => $file['id'],
                    'author_id' => $file['author']->id,
                    'created_at' => $file['created_on']
                ]);
                $localFile->syncedFiles()->create([
                    'ext_id' => $file['id'],
                    'project_id' => $project->id
                ]);
            }
        }
    }

    /**
     * Отправляет файл в Redmine
     *
     * @param IssueFile $file
     * @param Project $project
     * @param integer $issueId
     * @return void
     */
    protected function pushFile(IssueFile $file, Project $project, int $issueId): void
    {
        if ($credential = $file->author->credentials()->where('server_id', $this->server->id)->first()) {
            $this->connect($credential->api_key);
        } else {
            $this->connect();
        }

        try {
            $response = json_decode($this->client->attachment->upload(Storage::path($file->path)), true);
            $this->client->issue->attach($issueId, [
                'token' => $response['upload']['token'], 
                'filename' => $file->name, 
                'description' => $file->description
            ]);
            $files = $this->getFiles($issueId);
            $file->syncedFiles()->updateOrCreate(
                ['ext_id' => end($files)['id']],
                ['project_id' => $project->id]
            );
        } catch (\Throwable $th) {
            dump($th->getMessage());
        }
    }
}