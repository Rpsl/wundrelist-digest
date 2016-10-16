<?php

if (!$loader = include __DIR__ . '/vendor/autoload.php') {
    die('You must set up the project dependencies.');
}

$app = new \Cilex\Application('Cilex');

$app->register(
    new \Cilex\Provider\ConfigServiceProvider(),
    [
        'config.path' => realpath(__DIR__ . '/config/config.yml')
    ]
);

$app->command(new \Wunder\Command\DigestCommand());
$app->run();
