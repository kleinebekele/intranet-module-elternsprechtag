<?php

use Illuminate\Support\Facades\Route;
use Intranet\Modules\Elternsprechtag\Http\Controllers\ElternController;
use Intranet\Modules\Elternsprechtag\Http\Controllers\PlanController;
use Intranet\Modules\Elternsprechtag\Http\Controllers\SprechtagController;
use Intranet\Modules\Elternsprechtag\Support\Rechte;

Route::middleware(['web', 'auth'])
    ->prefix('modules/elternsprechtag')
    ->name('module.elternsprechtag.')
    ->group(function () {
        Route::get('/', fn () => redirect()->route(Rechte::istLehrer(auth()->user())
            ? 'module.elternsprechtag.plan.index'
            : 'module.elternsprechtag.sprechtage.index'))->name('index');

        // Verwaltung: Sprechtage anlegen, Eltern informieren, Stand der Lehrer.
        Route::get('sprechtage', [SprechtagController::class, 'index'])->name('sprechtage.index');
        // Ohne .pdf-Endung: der Webserver liefert *.pdf sonst als statische Datei (404).
        Route::get('sprechtage/elterninfo', [SprechtagController::class, 'elternPdf'])->name('sprechtage.elterninfo');
        Route::get('sprechtage/lehrerinfo', [SprechtagController::class, 'lehrerPdf'])->name('sprechtage.lehrerinfo');
        Route::get('sprechtage/anlegen', [SprechtagController::class, 'create'])->name('sprechtage.create');
        Route::post('sprechtage', [SprechtagController::class, 'store'])->name('sprechtage.store');
        Route::get('sprechtage/{sprechtag}', [SprechtagController::class, 'show'])->name('sprechtage.show');
        Route::get('sprechtage/{sprechtag}/bearbeiten', [SprechtagController::class, 'edit'])->name('sprechtage.edit');
        Route::put('sprechtage/{sprechtag}', [SprechtagController::class, 'update'])->name('sprechtage.update');
        Route::delete('sprechtage/{sprechtag}', [SprechtagController::class, 'destroy'])->name('sprechtage.destroy');
        Route::get('sprechtage/{sprechtag}/vorschau', [SprechtagController::class, 'vorschau'])->name('sprechtage.vorschau');
        Route::post('sprechtage/{sprechtag}/info', [SprechtagController::class, 'info'])->name('sprechtage.info');

        // Lehrer: eigener Terminplan je Sprechtag (Verwaltung auch für andere über ?lehrer=).
        Route::get('plan', [PlanController::class, 'index'])->name('plan.index');
        Route::get('plan/{sprechtag}', [PlanController::class, 'zeigen'])->name('plan.zeigen');
        Route::post('plan/{sprechtag}', [PlanController::class, 'speichern'])->name('plan.speichern');
        Route::post('plan/{sprechtag}/abschliessen', [PlanController::class, 'abschliessen'])->name('plan.abschliessen');
        Route::get('plan/{sprechtag}/pdf', [PlanController::class, 'pdf'])->name('plan.pdf');
        Route::get('plan/{sprechtag}/ansicht', [PlanController::class, 'ansicht'])->name('plan.ansicht');
        Route::post('plan/{sprechtag}/teilnahme', [PlanController::class, 'teilnahme'])->name('plan.teilnahme');
        Route::delete('plan/termin/{termin}', [PlanController::class, 'absagen'])->name('plan.absagen');
        Route::post('plan/termin/{termin}/notiz', [PlanController::class, 'notiz'])->name('plan.notiz');

        // Eltern: Termine buchen (Verwaltung auch für andere über ?eltern=).
        Route::get('termine', [ElternController::class, 'index'])->name('eltern.index');
        Route::post('termine/{sprechtag}', [ElternController::class, 'buchen'])->name('eltern.buchen');
        Route::post('termine/{sprechtag}/assistent', [ElternController::class, 'assistent'])->name('eltern.assistent');
        Route::post('termine/{sprechtag}/assistent/buchen', [ElternController::class, 'vorschlagBuchen'])->name('eltern.vorschlag');
        Route::get('termine/{sprechtag}/zeiten', [ElternController::class, 'zeiten'])->name('eltern.zeiten');
        Route::get('termine/{sprechtag}/liste', [ElternController::class, 'liste'])->name('eltern.liste');
        Route::post('termine/{sprechtag}/liste', [ElternController::class, 'listeMailen'])->middleware('throttle:5,10')->name('eltern.listemail');
        Route::post('termine/{sprechtag}/keine-zeit', [ElternController::class, 'keineZeit'])->name('eltern.keinezeit');
        Route::delete('termine/termin/{termin}', [ElternController::class, 'absagen'])->name('eltern.absagen');
    });
