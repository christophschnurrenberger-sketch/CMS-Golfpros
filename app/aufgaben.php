<?php
/** Aufgaben – klein gehalten und ohne eigene Seite: sie leben im Dashboard. */
require __DIR__ . '/../lib/bootstrap.php';
Auth::fordern('modul.dashboard');
Auth::csrfFordern();

switch (App::aktion()) {
    case 'neu':
        $titel = App::post('titel');
        if ($titel !== '') {
            Tenant::insert('tasks', [
                'titel'   => $titel,
                'faellig' => App::post('faellig') !== '' ? App::post('faellig') . ' 09:00:00' : null,
                'user_id' => Auth::id(),
                'customer_id' => App::postInt('customer_id'),
            ]);
            App::melden('Aufgabe angelegt.');
        }
        break;

    case 'erledigt':
        $id = App::postInt('id');
        $a = Tenant::find('tasks', $id);
        if ($a) {
            Tenant::update('tasks', $id, ['erledigt' => $a['erledigt'] ? null : Util::jetzt()]);
        }
        break;

    case 'loeschen':
        Tenant::delete('tasks', App::postInt('id'));
        App::melden('Aufgabe entfernt.');
        break;
}
App::zurueck();
