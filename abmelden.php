<?php
require __DIR__ . '/lib/bootstrap.php';
Auth::abmelden();
App::weiter('/login.php');
