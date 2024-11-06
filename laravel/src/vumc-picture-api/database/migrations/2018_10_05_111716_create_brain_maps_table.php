<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateBrainMapsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('brain_maps', function (Blueprint $table) {
            $table->uuid('id')->unique();
            $table->primary('id');
            $table->integer('user_id');
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->integer('age')->nullable();
            $table->string('GBM')->nullable();
            $table->text('brain_map_notes')->nullable();
            $table->string('brain_map_classification')->nullable();
            $table->string('glioma')->nullable();
            $table->boolean('shared_brain_map')->nullable();
            $table->boolean('is_research')->nullable();
            $table->decimal('expected_residual_volume', 10, 2)->nullable();
            $table->decimal('expected_resectability_index', 3, 2)->nullable();
            $table->date('mri_date')->nullable();
            $table->text('low_res_brain_map')->nullable();
            $table->text('high_res_brain_map')->nullable();
            $table->string('T1c_slice_file_url')->nullable();
            $table->string('T1w_slice_file_url')->nullable();
            $table->string('T2w_slice_file_url')->nullable();
            $table->string('FLAIR_slice_file_url')->nullable();
            $table->integer('folder_id')->unsigned()->nullable();
            $table->foreign('folder_id')->references('id')->on('folders')->onDelete('set null');
            $table->timestamp('visited_at')->nullable();
            $table->boolean('notified')->nullable();
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
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('brain_maps');
        Schema::enableForeignKeyConstraints();
    }
}
