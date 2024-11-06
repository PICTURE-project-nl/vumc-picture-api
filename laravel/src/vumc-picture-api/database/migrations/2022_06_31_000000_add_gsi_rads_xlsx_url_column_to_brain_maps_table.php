<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddGsiRadsXLSXURLColumnToBrainMapsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('brain_maps', function (Blueprint $table) {
            $table->text('gsi_rads_xlsx_url')->after('gsi_rads')->nullable();
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
            $table->dropColumn('gsi_rads_xlsx_url');
        });
    }
}
