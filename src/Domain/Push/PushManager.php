<?php
declare(strict_types=1);

namespace App\Domain\Push;

use App\Domain\User\User;
use ErrorException;
use Medoo\Medoo;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use stdClass;

class PushManager {

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var Medoo
     */
    private $database;
    /**
     * @var WebPush
     */
    private $push;

    protected $notificationTemplate = [
        "notification" => [
            "actions" => [],
            "title" => "",
            "body" => "",
            "icon" => "https://edziennik.ga/assets/icons/icon-512x512.png",
            "vibrate" => [
                100,
                50,
                100
            ],
            "data" => []
        ]
    ];

    public function __construct(LoggerInterface $logger, Medoo $database, WebPush $push) {
        $this->logger = $logger;
        $this->database = $database;
        $this->push = $push;
    }

    public function sendToUser(User $user, $title, $body, $actions, $data): bool {
        $dataSql = $this->database->select('push','subscription', [
            'login' => $user->getLogin()
        ]);

        try {
            foreach ($dataSql as $subscription) {

                if (!$subscription) continue;

                $payload = $this->getPayload($title, $body, $actions, $data);

                $sub = Subscription::create(json_decode($subscription, true));

                $this->push->sendNotification($sub, json_encode($payload));
            }

            foreach ($this->push->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $this->logger->notice("Message sent successfully.", ['subscription' => $endpoint]);
                    return true;
                } else {
                    $this->logger->error("Message failed to sent.", ['subscription' => $endpoint, 'reason' => $report->getReason()]);
                    return false;
                }
            }
            return false;

        } catch (ErrorException $e) {
            $this->logger->error($e);
            return false;
        }
    }

    /**
     * @param string $title
     * @param string $body
     * @param array $actions
     * @param array $data
     * @return array
     */
    private function getPayload($title, $body, $actions = [], $data = []): array {
        $payload = $this->notificationTemplate;

        $payload['notification']['title'] = $title;
        $payload['notification']['body'] = $body;
        $payload['notification']['actions'] = $actions;
        $payload['notification']['data'] = $data;

        return $payload;
    }

    public function test1(): stdClass {

        $r = new stdClass();

        $payload = [
            "notification" => [
                "actions" => [
                    [
                        "action" => "see",
                         "title" => "Pokaż mi swoje towary"
                    ],
                    [
                        "action" => "dontgiveafuck",
                        "title" => "I don't give a fuck"
                    ]
                ],
                "body" => "Beata Lew Kiedrowska cię nienawidzi i wystawiła ci kolejną chujową ocene bez powodu...",
                "icon" => "https://edziennik.ga/assets/icons/icon-512x512.png",
                "vibrate" => [
                    100,
                    50,
                    100
                ],
                "data" => [
                    "url" => "https://edziennik.ga/grade/",
                    "grade" => [
                        "id" => 12
                    ]
                ]
            ]
        ];

        try {

            $data = $this->database->select('push', [
                'subscription',
                'login'
            ]);

            foreach($data as $row) {

                $subscription = $row['subscription'];
                $login = $row['login'];

                $payload['notification']['title'] = 'Uważaj '.$login . '!';

                $sub = Subscription::create(json_decode($subscription, true));

                $this->push->sendNotification($sub, json_encode($payload));
            }


            foreach ($this->push->flush() as $report) {
                $endpoint = $report->getRequest()->getUri()->__toString();

                if ($report->isSuccess()) {
                    $this->logger->debug("[v] Message sent successfully for subscription {$endpoint}.");
                } else {
                    $this->logger->debug("[x] Message failed to sent for subscription {$endpoint}: {$report->getReason()}");
                }
            }
        } catch (ErrorException $e) {

        }
        return $r;
    }
}