<?php

namespace Wunder\Command;

use Carbon\Carbon;
use Cilex\Command\Command;
use League\Plates\Engine;
use Wilsonpinto\Wunderlist\Wunderlist;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class DigestCommand extends Command
{
    /** @var  Wunderlist */
    private $wunder;

    private $config;

    protected function configure()
    {
        $this
            ->setName('digest:run')
            ->setDescription('Fetch tasks and send digest');
    }

    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->config = $this->getService('config');

        $this->wunder = new Wunderlist(
            $this->config['wunderlist']['client_id'],
            $this->config['wunderlist']['client_secret'],
            'http://localhost/callback',
            $this->config['wunderlist']['token']
        );

        $this->wunder->refreshAccessToken($this->config['wunderlist']['token']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            list($tasks_overdue, $tasks_today) = $this->getTasks();

            if (empty($tasks_today) && empty($tasks_overdue)) {
                exit();
            }

            $template = $this->renderTemplate($tasks_overdue, $tasks_today);
        } catch (\Exception $e) {
            $template = $e->getMessage();
        }

        $this->sendMail($template);
    }

    private function getTasks()
    {
        $res = $this->wunder->lists()->all();

        if (empty($res)) {
            exit;
        }

        $tasks_today = $tasks_overdue = [];

        foreach ($res as $list) {
            $tasks = $this->wunder->tasks()->all($list->id);

            if (empty($tasks)) {
                continue;
            }

            foreach ($tasks as $task) {
                if (empty($task->due_date)) {
                    continue;
                }

                $due_date = Carbon::createFromFormat(
                    'Y-m-d',
                    $task->due_date
                );

                if ($due_date->eq(Carbon::now())) {
                    $tasks_today[] = $task;
                } elseif ($due_date->lt(Carbon::now())) {
                    $tasks_overdue[] = $task;
                }
            }
        }

        return [$tasks_overdue, $tasks_today];
    }

    private function renderTemplate($tasks_overdue = [], $tasks_today = [])
    {
        $templates = new Engine(realpath(__DIR__ . '/../templates'));

        return $templates->render('digest', ['today' => $tasks_today, 'overdue' => $tasks_overdue]);
    }


    private function sendMail($html)
    {
        $data = [
            'from' => sprintf('Wunder Digest <wundrelist@%s>', $this->config['mailgun']['domain']),
            'to' => $this->config['app']['to_email'],
            'subject' => 'Wunder Digest ' . date('d/m'),
            'html' => $html,
        ];

        $data = http_build_query($data);

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' =>
                    "Content-type: application/x-www-form-urlencoded\r\n" .
                    "Content-Length: " . strlen($data) . "\r\n" .
                    "Authorization: Basic " . base64_encode($this->config['mailgun']['key']) . "\r\n",
                'content' => $data,
            ],
        ];

        $stream = stream_context_create($opts);

        file_get_contents(
            sprintf('https://api.mailgun.net/v2/%s/messages', $this->config['mailgun']['domain']),
            false,
            $stream
        );
    }
}