<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProviderColumnToOuathClientsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {

        // TODO disabled migration because it isn't necessary at the moment

        /*
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->string('provider')->after('secret')->nullable();
        }); */
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {

        // TODO disabled migration because it isn't necessary at the moment
        
        /*
        Schema::table('ouath_clients', function (Blueprint $table) {
            $table->dropColumn('provider');
        }); */
    }
}
