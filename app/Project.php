<?php

namespace App;

use App\IssueTracker\Contracts\ProjectContract;
use App\IssueTracker\Gogs\GogsProject;
use App\IssueTracker\Redmine\RedmineProject;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class Project
 * @package App
 *
 * @property integer $id
 * @property Server $server
 * @property integer $ext_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property-read Label[]|Collection $enumerations
 * @property-read Label[]|Collection $labels
 * @property-read Label[]|Collection $trackers
 * @property-read Label[]|Collection $statuses
 * @property-read Label[]|Collection $priorities
 */
class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'server_id', 'ext_id', 'parent_id', 'slug', 'name', 'description'
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function milestones()
    {
        return $this->hasMany(Milestone::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function issues()
    {
        return $this->hasMany(Issue::class);
    }

    /**
     * Возвращает запрос задач для отправки в Redmine (задачи которые обновлены, но изменения не отправлены)
     *
     * @return Builder
     */
    public function queryIssuesToPush()
    {
        return $this->issues()->whereHas('syncedIssues', function ($query) {
            $query->whereColumn('synced_issues.updated_at', '<', 'issues.updated_at')
                ->where('project_id', $this->id);
        });
    }

    /**
     * Return HasMany relation of issues to push from mirror project
     *
     * @param Project $project
     * @return Builder
     */
    public function issuesToPush(?Project $project = null): HasMany
    {
        if (!$project) {
            return $this->issues()->whereHas('syncedIssues', function ($query) {
                $query->whereColumn('synced_issues.updated_at', '<', 'issues.updated_at');
            });
        }
        
        $new = $this->issues()->whereDoesntHave('syncedIssues', function ($query) use ($project) {
            $query->where('project_id', $project->id);
        }); 
        $newMirror = $project->issues()->whereDoesntHave('syncedIssues', function ($query) {
            $query->where('project_id', $this->id);
        }); 
        $exists = $this->issues()->whereHas('syncedIssues', function ($query) {
            $query->whereColumn('synced_issues.updated_at', '<', 'issues.updated_at');
        });
        $existsMirror = $project->issues()->whereHas('syncedIssues', function ($query) {
            $query->whereColumn('synced_issues.updated_at', '<', 'issues.updated_at');
        });
        return $existsMirror->unionAll($exists)->unionAll($new)->unionAll($newMirror);
    }

    public function syncedIssues()
    {
        return $this->hasMany(SyncedIssue::class);
    }

    public function enumerations()
    {
        return $this->server->hasMany(Label::class);
    }

    public function labels()
    {
        return $this->enumerations()->whereNull('type');
    }

    public function trackers()
    {
        return $this->enumerations()->where('type', 'tracker');
    }

    public function statuses()
    {
        return $this->enumerations()->where('type', 'status');
    }

    public function priorities()
    {
        return $this->enumerations()->where('type', 'priority');
    }

    /**
     * Возвращает коллекцию отформатированную в соответствии с дочерними проектами (добавляет табуляцию для дочерних проектов)
     *
     * @param Collenction $projects
     * @param integer $parentId
     * @param integer $tabsCount
     * @return Collenction
     */
    public static function sortByParent($projects, $parentId, $tabsCount = 0) {
        $output = [];
        foreach ($projects as $item) {
            if ($item->parent_id === $parentId) {
                $childs = self::sortByParent($projects, $item->id, $tabsCount + 1);
                $item->name = str_repeat('    ', $tabsCount) . $item->name;
                $output[] = $item;
                $output = array_merge($output, $childs);
            }
        }
        return $output;
    }
}
