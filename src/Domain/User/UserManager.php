<?php


namespace App\Domain\User;

use App\Domain\Diary\DiaryManager;
use App\Domain\DomainException\WrongPasswordOrLoginException;
use Medoo\Medoo;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\CircularException;
use PHPHtmlParser\Exceptions\NotLoadedException;
use PHPHtmlParser\Exceptions\StrictException;
use PHPHtmlParser\Exceptions\UnknownChildTypeException;
use Psr\Log\LoggerInterface;
use stdClass;

class UserManager {
    /**
     * @var Medoo $database
     */
    protected $database;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var DiaryManager
     */
    private $diaryManager;

    /**
     * UserManager constructor.
     * @param LoggerInterface $logger
     * @param Medoo $database
     * @param DiaryManager $diaryManager
     */
    public function __construct(LoggerInterface $logger, Medoo $database, DiaryManager $diaryManager) {
        $this->logger = $logger;
        $this->database = $database;
        $this->diaryManager = $diaryManager;
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws WrongPasswordOrLoginException
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function login(User $user): stdClass {
        return $this->diaryManager->login($user);
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws WrongPasswordOrLoginException
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws UnknownChildTypeException
     */
    public function getLessonPlan($user): stdClass {
        return $this->diaryManager->getLessonPlan($user);
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws WrongPasswordOrLoginException
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function getGrades($user): stdClass {
        return $this->diaryManager->getGrades($user);
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws WrongPasswordOrLoginException
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function getExams($user) {
        return $this->diaryManager->getExams($user);
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws WrongPasswordOrLoginException
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function getHomeworks($user) {
        return $this->diaryManager->getHomeworks($user);
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws WrongPasswordOrLoginException
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     */
    public function getSubjects($user): stdClass {
        return $this->diaryManager->getSubjects($user);
    }

    /**
     * @param $user User
     * @return array
     */
    public function saveCredentials(User $user) {
        // Save user to database in order to automated syncs
        $data = $this->database->select('users', [
            'id'
        ], [
           'login' => $user->getLogin()
        ]);

        if (count($data) == 1) {
            return [
              'credentials_id' => $data[0]['id']
            ];
        }

        $sqlData = [
            'login' => $user->getLogin(),
            'password_md5' => $user->getPassMd5()
        ];

        if ($user->getChildLogin()) {
            $sqlData['child_login'] = $user->getChildLogin();
        }

        // Save
        $this->database->insert('users', $sqlData);

        return [
            'credentials_id' => $this->database->id()
        ];
    }

    /**
     * @param User $user
     * @param string $subscription
     * @return array
     */
    public function savePush(User $user, string $subscription) {
        $data = $this->database->select('push', 'id', [
            'AND' => [
                'subscription' => $subscription,
                'login' => $user->getLogin()
            ]
        ]);

        if (count($data) >= 1) {
            return [
                'push_id' => $data[0]
            ];
        }

        $sqlData = [
            'login' => $user->getLogin(),
            'subscription' => $subscription
        ];

        $this->database->insert('push', $sqlData);

        return [
            'push_id' => $this->database->id()
        ];
    }
}