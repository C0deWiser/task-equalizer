<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIssueCommentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('issue_comments', function (Blueprint $table) {
            $table->id();
            $table->text('body');
            $table->integer('ext_id');
            $table->unsignedBigInteger('issue_id');
            $table->unsignedBigInteger('author_id');
            $table->foreign('issue_id')->references('id')->on('issues')->onDelete('cascade');
            $table->foreign('author_id')->references('id')->on('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('issue_comments');
    }
}
