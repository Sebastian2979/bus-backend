<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------
        // agency.txt
        // -------------------------------------------------------
        Schema::create('agencies', function (Blueprint $table) {
            $table->string('agency_id')->primary();
            $table->string('agency_name');
            $table->string('agency_url');
            $table->string('agency_timezone');
            $table->string('agency_lang', 10)->nullable();
            $table->string('agency_phone')->nullable();
            $table->timestamps();
        });

        // -------------------------------------------------------
        // stops.txt
        // -------------------------------------------------------
        Schema::create('stops', function (Blueprint $table) {
            $table->string('stop_id')->primary();
            $table->string('stop_code')->nullable();
            $table->string('stop_name');
            $table->text('stop_desc')->nullable();
            $table->decimal('stop_lat', 10, 7);
            $table->decimal('stop_lon', 10, 7);
            $table->string('zone_id')->nullable();
            $table->string('stop_url')->nullable();
            $table->unsignedTinyInteger('location_type')->default(0); // 0=stop, 1=station, 2=entrance
            $table->string('parent_station')->nullable();
            $table->string('platform_code')->nullable();
            $table->timestamps();

            $table->index(['stop_lat', 'stop_lon']); // für Umkreissuche
            $table->index('parent_station');
        });

        // -------------------------------------------------------
        // routes.txt
        // -------------------------------------------------------
        Schema::create('routes', function (Blueprint $table) {
            $table->string('route_id')->primary();
            $table->string('agency_id')->nullable();
            $table->string('route_short_name')->nullable();
            $table->string('route_long_name')->nullable();
            $table->text('route_desc')->nullable();
            $table->unsignedTinyInteger('route_type'); // 0=Tram, 1=Metro, 2=Rail, 3=Bus …
            $table->string('route_url')->nullable();
            $table->string('route_color', 6)->nullable();
            $table->string('route_text_color', 6)->nullable();
            $table->timestamps();

            $table->foreign('agency_id')->references('agency_id')->on('agencies')->nullOnDelete();
            $table->index('route_type');
        });

        // -------------------------------------------------------
        // calendar.txt
        // -------------------------------------------------------
        Schema::create('calendars', function (Blueprint $table) {
            $table->string('service_id')->primary();
            $table->boolean('monday');
            $table->boolean('tuesday');
            $table->boolean('wednesday');
            $table->boolean('thursday');
            $table->boolean('friday');
            $table->boolean('saturday');
            $table->boolean('sunday');
            $table->date('start_date');
            $table->date('end_date');
            $table->timestamps();
        });

        // -------------------------------------------------------
        // calendar_dates.txt
        // -------------------------------------------------------
        Schema::create('calendar_dates', function (Blueprint $table) {
            $table->id();
            $table->string('service_id');
            $table->date('date');
            $table->unsignedTinyInteger('exception_type'); // 1=added, 2=removed
            $table->timestamps();

            $table->unique(['service_id', 'date']);
            $table->index('service_id');
            $table->index('date');
        });

        // -------------------------------------------------------
        // shapes.txt  (optional aber empfohlen für Kartenanzeige)
        // -------------------------------------------------------
        Schema::create('shapes', function (Blueprint $table) {
            $table->id();
            $table->string('shape_id');
            $table->decimal('shape_pt_lat', 10, 7);
            $table->decimal('shape_pt_lon', 10, 7);
            $table->unsignedInteger('shape_pt_sequence');
            $table->float('shape_dist_traveled')->nullable();
            $table->timestamps();

            $table->index('shape_id');
            $table->index(['shape_id', 'shape_pt_sequence']);
        });

        // -------------------------------------------------------
        // trips.txt
        // -------------------------------------------------------
        Schema::create('trips', function (Blueprint $table) {
            $table->string('trip_id')->primary();
            $table->string('route_id');
            $table->string('service_id');
            $table->string('trip_headsign')->nullable();
            $table->string('trip_short_name')->nullable();
            $table->unsignedTinyInteger('direction_id')->nullable(); // 0 oder 1
            $table->string('block_id')->nullable();
            $table->string('shape_id')->nullable();
            $table->unsignedTinyInteger('wheelchair_accessible')->nullable();
            $table->unsignedTinyInteger('bikes_allowed')->nullable();
            $table->timestamps();

            $table->foreign('route_id')->references('route_id')->on('routes')->cascadeOnDelete();
            $table->index('route_id');
            $table->index('service_id');
            $table->index('shape_id');
        });

        // -------------------------------------------------------
        // stop_times.txt  (größte Tabelle — kein timestamps() für Performance)
        // -------------------------------------------------------
        Schema::create('stop_times', function (Blueprint $table) {
            $table->id();
            $table->string('trip_id');
            $table->string('arrival_time', 8)->nullable();   // HH:MM:SS (kann >24h sein!)
            $table->string('departure_time', 8)->nullable();
            $table->string('stop_id');
            $table->unsignedSmallInteger('stop_sequence');
            $table->string('stop_headsign')->nullable();
            $table->unsignedTinyInteger('pickup_type')->default(0);
            $table->unsignedTinyInteger('drop_off_type')->default(0);
            $table->float('shape_dist_traveled')->nullable();
            $table->unsignedTinyInteger('timepoint')->nullable();

            // Kein primary key auf trip_id+stop_sequence, da Import-Performance leidet
            $table->index('trip_id');
            $table->index('stop_id');
            $table->index(['stop_id', 'departure_time']); // Kernindex für Abfahrtsabfragen
            $table->index(['trip_id', 'stop_sequence']);

            $table->foreign('trip_id')->references('trip_id')->on('trips')->cascadeOnDelete();
            $table->foreign('stop_id')->references('stop_id')->on('stops')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stop_times');
        Schema::dropIfExists('trips');
        Schema::dropIfExists('shapes');
        Schema::dropIfExists('calendar_dates');
        Schema::dropIfExists('calendars');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('stops');
        Schema::dropIfExists('agencies');
    }
};
