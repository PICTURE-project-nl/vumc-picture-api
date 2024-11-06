<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUploadsTable extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('id')->unique();
            $table->primary('id');
            $table->string('process_state');
            $table->integer('user_id');
            $table->uuid('brain_map_id')->nullable();
            $table->string('anonymized_nifti_file_url')->nullable();
            $table->text('nifti_metadata')->nullable();
            $table->text('auto_segmentation')->nullable();
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
        Schema::dropIfExists('uploads');
    }
}
