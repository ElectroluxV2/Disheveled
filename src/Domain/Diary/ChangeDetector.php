<?php

namespace App\Domain\Diary;

use App\Domain\DomainException\WrongPasswordOrLoginException;
use App\Domain\Push\PushManager;
use App\Domain\User\User;
use Curl;
use Exception;
use Medoo\Medoo;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\CircularException;
use PHPHtmlParser\Exceptions\r\notLoadedException;
use PHPHtmlParser\Exceptions\StrictException;
use Psr\Log\LoggerInterface;
use stdClass;

class ChangeDetector {

    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var Medoo
     */
    private $database;
    /**
     * @var Dom
     */
    private $dom;
    /**
     * @var Curl
     */
    private $curl;
    /**
     * @var PushManager
     */
    private $pushManager;
    /**
     * @var DiaryManager
     */
    private $diaryManager;
    /**
     * @var TranslationManager
     */
    private $translationManager;

    /**
     * ChangeDetector constructor.
     * @param PushManager $pushManager
     * @param DiaryManager $diaryManager
     * @param LoggerInterface $logger
     * @param Medoo $database
     * @param Dom $dom
     * @param Curl $curl
     * @param TranslationManager $translationManager
     */
    public function __construct(PushManager $pushManager, DiaryManager $diaryManager, LoggerInterface $logger, Medoo $database, Dom $dom, Curl $curl, TranslationManager $translationManager) {
        $this->pushManager = $pushManager;
        $this->diaryManager = $diaryManager;
        $this->logger = $logger;
        $this->database = $database;
        $this->dom = $dom;
        $this->curl = $curl;
        $this->translationManager = $translationManager;
    }

    public function anyChanges(): bool {
        $anyChanges = false;
        // All users
        $users = $this->database->select('users', ['login', 'password_md5']);

        foreach ($users as $userData)  {

            $user = new User($userData['login'], $userData['password_md5']);
            // Check last update
            try {
                $lastUpdate = $this->diaryManager->getLastUpdate($user);
                $lastUpdateMd5 = md5($lastUpdate);

                $data = $this->database->get('changes', [
                    'lastUpdate'
                ], [
                    'login' => $user->getLogin()
                ]);

                if (empty($data)) {

                    $sqlData = [
                        'lastUpdate' => $lastUpdateMd5,
                        'login' => $user->getLogin()
                    ];
                    // insert
                    $this->database->insert('changes', $sqlData);
                } else {
                    $toUpdate = false;
                    if ($lastUpdateMd5 != $data['lastUpdate']) {
                        $toUpdate = true;
                    }

                    if (!$toUpdate) continue;

                    // Update base
                    $this->database->update('changes', [
                        'lastUpdate' => $lastUpdateMd5,
                    ], [
                        'login' => $user->getLogin()
                    ]);
                }

                $anyChanges = true;

                // Add this user as a task for another cron
                $this->database->insert('tasks', [
                   'login' => $user->getLogin()
                ]);
            } catch (WrongPasswordOrLoginException $e) {
                // Remove invalid data from base
                $data = $this->database->delete('users', [
                    'login' => $user->getLogin()
                ]);
                $this->logger->warning('Removing user due to invalid credentials.', ['rows_affected' => $data->rowCount(),'user' => $user->jsonSerialize()]);
                continue;
            }
        }
        return $anyChanges;
    }

    /**
     *  Check in sql if any account needs to deeper change detection
     */
    public function deepChanges(): bool {

        $anyChanges = false;

        // Only one task at once
        $data = $this->database->get('tasks', [
            'id',
            'login'
        ]);

        if (empty($data)) {
            $this->logger->warning('No work left to do!');
            return false;
        }

        $taskID = $data['id'];

        // Get user password
        $userData = $this->database->get('users', [
            'child_login',
            'password_md5'
        ], [
            'login' => $data['login']
        ]);

        if (empty($userData['password_md5'])) {
            $this->logger->warning('Missing password for user!', $userData);
            return false;
        }

        $user = new User($data['login'], $userData['password_md5']);
        if (!empty($userData['child_login'])) {
            $user->setChildLogin($userData['child_login']);
        }

        $grades = null;
        try {
            $grades = $this->diaryManager->getGrades($user)->grades;
        } catch (Exception $e) {
            $this->logger->warning('Exception during update!', $e->getTrace());
            return false;
        }

        // Get last grades
        $dataFromBase = $this->database->get('changes', 'lastGrades', [
            'login' => $user->getLogin()
        ]);

        //$this->logger->debug('$lastGradesEscaped:'.$dataFromBase);

        $lastGrades = json_decode($dataFromBase, false, 5, JSON_INVALID_UTF8_IGNORE);

        //$this->logger->debug('$lastGrades:'.json_encode($lastGrades, JSON_PRETTY_PRINT));

        for ($lessonIndex = 0; $lessonIndex < count($grades); $lessonIndex++) {

            $is = array_merge($grades[$lessonIndex]->primePeriod, $grades[$lessonIndex]->latterPeriod);

            if (!isset($lastGrades[$lessonIndex])) {
                $lastGrades[$lessonIndex] = new stdClass();
                $lastGrades[$lessonIndex]->primePeriod = [];
                $lastGrades[$lessonIndex]->latterPeriod = [];
            }

            $was = array_merge($lastGrades[$lessonIndex]->primePeriod, $lastGrades[$lessonIndex]->latterPeriod);

            //$this->logger->debug('$is: '.json_encode($is));
            //$this->logger->debug('$was: '.json_encode($was));

            for ($i = 0; $i < count($is); $i++) {

                if (($i == 0) and ($lessonIndex == 0)) {
                    $is[0]->category = 'NIENAWIDZĘ CIE';
                    $is[0]->value = '6 XD chaiałbyś';
                    $is[0]->grade = '%%';
                }

                $g1 = $is[$i];
                $isNewGrade = !isset($was[$i]);

                if ($isNewGrade) {
                    $g2 = new stdClass();
                } else {
                    $g2 = $was[$i];
                }

                $df = array_diff(get_object_vars($g1), get_object_vars($g2));

                if (($df == null) || (empty($df))) {
                    continue;
                }

                $anyChanges = true;

                $changesStrings = [];
                foreach ($df as $whatChanged => $newValue) {
                    $lastValue = $g2->$whatChanged;

                    if (empty($lastValue)) {
                        $whatChangedTranslated = $this->translationManager->translate($whatChanged, 0);
                        $compiledChangeString = sprintf('Nowa %s: "%s".', $whatChangedTranslated, $newValue);

                    } else {
                        $whatChangedTranslated = $this->translationManager->translate($whatChanged, 1);
                        $compiledChangeString = sprintf('Zmiana %s, z "%s", na "%s".', $whatChangedTranslated, $lastValue, $newValue);

                    }

                    array_push($changesStrings, $compiledChangeString);
                    //$this->logger->debug($compiledChangeString);
                }

                $titleText = $grades[$lessonIndex]->name.' - ';
                $titleText .= $isNewGrade ? 'nowa ocena' : 'zmiana oceny';

                $bodyText = '';
                if (!$isNewGrade) {
                    $bodyText = sprintf('Nauczyciel %s wprowadził następujące zmiany do Twojej oceny: ', $g1->issuer);
                    foreach ($changesStrings as $change) {
                        $bodyText .= $change.' ';
                    }
                } else {
                    $forWhat = empty($g1->description) ? $g1->category : $g1->description;
                    $bodyText = sprintf('%s - %s, wystawiona %s przez %s.', $g1->grade, $forWhat, $g1->date, $g1->issuer);
                }

                // Notify user about change
                $this->pushManager->sendToUser(
                    $user,
                    $titleText,
                    $bodyText,
                    [
                        [
                            'title' => 'who cares',
                            'action' => 'dismiss',
                        ], [
                        'title' => 'pokaż',
                        'action' => 'show',
                    ]
                    ],
                    [
                        'lessonName' => $grades[$lessonIndex]->name,
                        'oldGrade' => $g2,
                        'newGrade' => $g1,
                        'login' => $user->getLogin(),
                        'url' => '/grades'
                    ]
                );

                $this->logger->debug('Data send', [
                    'lessonName' => $grades[$lessonIndex]->name,
                    'oldGrade' => $g2,
                    'newGrade' => $g1,
                    'login' => $user->getLogin(),
                ]);
            }
        }

        //$this->logger->debug('$gradesEscaped: '.json_encode($grades, JSON_INVALID_UTF8_SUBSTITUTE));


        // Replace last grades
        $this->database->update('changes', [
            'lastGrades' => json_encode($grades, JSON_INVALID_UTF8_SUBSTITUTE)
        ], [
            'login' => $user->getLogin()
        ]);

        // Remove task after finish
        $this->database->delete('tasks', [
            'id' => $taskID
        ]);

        return $anyChanges;
    }
}