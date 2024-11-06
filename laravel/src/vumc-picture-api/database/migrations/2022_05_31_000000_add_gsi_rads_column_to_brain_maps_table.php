<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGsiRadsColumnToBrainMapsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('brain_maps', function (Blueprint $table) {
            $table->text('gsi_rads')->after('high_res_brain_map')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('brain_maps', function (Blueprint $table) {
            $table->dropColumn('gsi_rads');
        });
    }
}
