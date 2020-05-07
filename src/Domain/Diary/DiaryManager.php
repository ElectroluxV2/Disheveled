<?php

namespace App\Domain\Diary;

use App\Domain\DomainException\WrongPasswordOrLoginException;
use App\Domain\User\User;
use Curl;
use DateInterval;
use DateTime;
use DateTimeZone;
use DOMDocument;
use Medoo\Medoo;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\Collection;
use PHPHtmlParser\Dom\HtmlNode;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\CircularException;
use PHPHtmlParser\Exceptions\NotLoadedException;
use PHPHtmlParser\Exceptions\StrictException;
use PHPHtmlParser\Exceptions\UnknownChildTypeException;
use Psr\Log\LoggerInterface;
use stdClass;

class DiaryManager {

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
     * DiaryManager constructor.
     * @param LoggerInterface $logger
     * @param Medoo $database
     * @param Dom $dom
     * @param Curl $curl
     */
    public function __construct(LoggerInterface $logger, Medoo $database, Dom $dom, Curl $curl) {
        $this->logger = $logger;
        $this->database = $database;
        $this->dom = $dom;
        $this->curl = $curl;
    }

    public function login(User $user): stdClass {
        try {
            $r = new stdClass();

            $url = 'https://nasze.miasto.gdynia.pl/ed_miej/login.pl';
            $params = [
                'user' => $user->getLogin(),
                'pass_md5' => $user->getPassMd5(),
                'action' => 'set',
            ];

            $response = $this->curl->post($url, $params, $user->getLogin());

            // Wrong password and/or login
            if (strpos($response, 'Niepoprawna')) {
                $this->logger->notice('Access denied for User', $user->jsonSerialize());
                throw new WrongPasswordOrLoginException("Wrong password and/or login!");
            }

            $this->loadDomS($response);

            // META HTTP-EQUIV="Refresh" CONTENT="0;
            $sid = null;
            $meta = $this->dom->getElementsByTag('meta')[0];
            $sid = substr($meta->getAttribute('content'), 26, -10);

            // Now call url with sid parameter and force to load child selection if parent
            $response = $this->curl->get('https://nasze.miasto.gdynia.pl/ed_miej/login_check.pl', [
                'sid' => $sid,
                'url_back' => 'https://nasze.miasto.gdynia.pl/ed_miej/display.pl?form=ed_plan_zajec&user=' . $user->getLogin()
            ], $user->getLogin());

            $this->loadDomS($response);

            // Get hash for AJAX request
            $ajaxHashNode = $this->dom->getElementById('f_uczen_value_div');
            $ajaxHash = $ajaxHashNode->getAttribute('hash');

            // Get User info <span id=userinfo>update<div id=userinfo>username</></>
            $userInfo = null;
            $userInfo = $this->dom->getElementById('userinfo');

            $lastUpdate = substr($userInfo->text, 26, -1);
            $dateTimeLastUpdate = DateTime::createFromFormat('Y-m-d H:i:s', $lastUpdate);
            $dateTimeLastUpdate->setTimeZone(new DateTimeZone('Europe/Warsaw'));

            $this->loadDomS($userInfo->innerHtml);

            $userName = null;
            $node = $this->dom->getElementById('userinfo');
            $userName = substr($node->text, 23, -1);

            $r->userName = $userName;
            $r->lastUpdate = $dateTimeLastUpdate->format('D M d Y H:i:s O'); // JS format

            // Get child login
            $response = $this->curl->get('https://nasze.miasto.gdynia.pl/ed_miej/action_ajax.pl', [
                'filter' => '',
                'extra_filter' => '{}',
                'value_sets' => '{}',
                'page' => 0,
                'name' => 'uczen',
                'hash' => $ajaxHash,
            ], $user->getLogin());

            $code = $this->loadDomS($response);
            if ($code) {
                $r->loginState = $code;
                return $r;
            }

            // Extract data
            $child = new stdClass;
            $child->login = $this->dom->getElementsByClass('link_list_element')[0]->getAttribute('key');
            $child->name = $this->dom->find('[key=p.imie]')[0]->getAttribute('val');
            $child->surname = $this->dom->find('[key=p.nazwisko]')[0]->getAttribute('val');
            $child->school = $this->dom->find('[key=s.nazwa]')[0]->getAttribute('val');

            // Child and Parent can be same, which means it's child account
            if ($userName == $child->name . ' ' . $child->surname) {
                $r->accountType = 'child';
                $r->school = $child->school;
                $r->name = $child->name;
                $r->surname = $child->surname;
                $r->login = $child->login;
            } else {
                $r->accountType = 'parent';
                $r->child = $child;
            }

            // Save sid
            $_SESSION['sid'] = $sid;
            $user->setSid($sid);

            $this->logger->notice('Access granted for User', $user->jsonSerialize());
            return $r;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    private function loadDomS($str): int {
        try {
            $this->dom->loadStr($str);
            return 0;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param $string string
     * @param $encoding string
     * @return string
     */
    private function mb_ucfirst($string, $encoding = 'UTF-8'): string {
        $strlen = mb_strlen($string, $encoding);
        $firstChar = mb_substr($string, 0, 1, $encoding);
        $then = mb_substr($string, 1, $strlen - 1, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }

    /**
     * @param User $user
     * @param $callback
     * @param null $param
     * @return int
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws WrongPasswordOrLoginException
     */
    private function renewSessionIfNeeded(User $user, $callback, $param = null) {
        // Session expired
        try {
            if ($this->dom->getElementById('pass')) {
                $this->logger->notice('Access denied for User, renewing session now', $user->jsonSerialize());

                // Pass only if logged
                $this->login($user);

                // Now back to, we have valid session
                return call_user_func(array($this, $callback), $param);
            } else {
                return 0;
            }

        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param string $html
     * @return string
     */
    private function fixShit($html): string {
        // Fix this shitty html
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $converted = mb_convert_encoding($html, 'HTML-ENTITIES', "UTF-8");
        $dom->loadHTML($converted);

        // Strip wrapping <html> and <body> tags
        $mock = new DOMDocument;
        $body = $dom->getElementsByTagName('body')->item(0);
        foreach ($body->childNodes as $child) {
            $mock->appendChild($mock->importNode($child, true));
        }
        return trim($mock->saveHTML());
    }

    /**
     * @param User $user
     * @return stdClass
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws UnknownChildTypeException
     * @throws WrongPasswordOrLoginException
     */
    public function getLessonPlan($user): stdClass {
        try {
            $r = new stdClass();
            // First we gonna act like already logged in user
            $url = 'https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_plan_zajec.pl';

            // Different for parent and child
            $params = [
                'daty' => date('Y-m-d',time()+( 1 - date('w'))*24*3600),
                'print_version' => 1
            ];

            if (!($user->getChildLogin())) {
                $params['uczen'] = $user->getLogin();
            } else {
                $params['user'] = $user->getLogin();
                $params['uczen'] = $user->getChildLogin();
            }

            $response = $this->curl->get($url, $params, $user->getLogin());
            // Fix html
            $fixed = $this->fixShit($response);
            // Load dom
            $this->loadDomS($fixed);

            $this->logger->info('Get plan invoked by User', $user->jsonSerialize());
            // Session expired
            $this->renewSessionIfNeeded($user, 'getLessonPlan', $user);

            // School plan for this week
            $tablePlanNow = $this->dom->find('#printContent > table');

            $params['daty'] =  date('Y-m-d',time()+( 7 - date('w'))*24*3600);
            $response = $this->curl->get($url, $params, $user->getLogin());
            // Fix html
            $fixed = $this->fixShit($response);
            // Load dom
            $this->loadDomS($fixed);

            // School plan for next week
            $tablePlanNext = $this->dom->find('#printContent > table');

            // Parse two at once
            $schoolPlan = [
                'monday' => [],
                'tuesday' => [],
                'wednesday' => [],
                'thursday' => [],
                'friday' => [],
            ];

            /** @var Collection $trs1 */
            $trs1 = $tablePlanNow->find('tr');
            $trs2 = $tablePlanNext->find('tr');
            $row = 0;
            for ($tr = 0; $tr < $trs1->count(); $tr++) {
                /** @var HtmlNode $node */
                $node = $trs1[$tr];

                // This is where all days are in order by lessons horizontally
                if ((strpos($node->innerhtml(), '<tr>') === false) && (strpos($node->innerhtml(), '<b>') === false) || (strpos($node->innerhtml(), '<b><a href='))) {
                    // Skip that
                    continue;
                }
                $row++; // Row is lesson number

                // Time is always at 0 index 15
                $str = $node->firstChild()->innerhtml();
                $time = substr($str, -14, -1);

                // Any grater than 0 is lesson
                for ($i = 1; $i < count($node->getChildren()); $i++) {

                    // If day has passed use plan for next week
                    if (date('w') > $i) {
                        $node = $trs2[$tr];
                    }

                    /** @var HtmlNode $td */
                    $td = $node->getChildren()[$i];

                    $lesson = new stdClass;
                    $lesson->time = $time;

                    $date = date('d/m/y',time()+( 1 - date('w'))*24*3600);
                    $dtmp = DateTime::createFromFormat('d/m/y', $date);
                    if (date('w') > $i) {
                        // Next week
                        $dtmp->add(new DateInterval('P'.(7+$i-1).'D')); // P1D means a period of 1 day
                    } else {
                        $dtmp->add(new DateInterval('P'.($i-1).'D')); // P1D means a period of 1 day
                    }
                    $lesson->date = $dtmp->format('D M d Y 00:00:00 O');

                    if (empty(trim($td->innerHtml()))) {
                        $lesson->empty = true;
                    } else {
                        $table = $td->find('td');
                        $raw = html_entity_decode($table->innerHtml());
                        $parts = explode('<br />', $raw); // Name | Time | Teacher

                        $lesson->name = $this->mb_ucfirst(mb_strtolower($parts[0], 'UTF-8'));
                        $lesson->time = trim($parts[1]);
                        $lesson->teacher = ucwords(mb_strtolower(trim($parts[2]), 'UTF-8'));
                    }

                    // Push to plan
                    switch ($i) {
                        case 1:
                            array_push($schoolPlan['monday'], $lesson);
                            break;
                        case 2:
                            array_push($schoolPlan['tuesday'], $lesson);
                            break;
                        case 3:
                            array_push($schoolPlan['wednesday'], $lesson);
                            break;
                        case 4:
                            array_push($schoolPlan['thursday'], $lesson);
                            break;
                        case 5:
                            array_push($schoolPlan['friday'], $lesson);
                            break;
                    }
                }
            }
            $r->plan = $schoolPlan;
            return $r;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (UnknownChildTypeException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (WrongPasswordOrLoginException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param User $user
     * @return stdClass
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws WrongPasswordOrLoginException
     */
    public function getGrades($user): stdClass {
        try {
            $r = new stdClass();
            // First we gonna act like already logged in user
            $url = 'https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_oceny_ucznia.pl';

            // Different for parent and child
            $params = ['print_version' => 1];
            if ($user->getChildLogin()) {
                $params['uczen_login'] = $user->getChildLogin();
            } else {
                $params['uczen_login'] = $user->getLogin();
            }

            $response = $this->curl->get($url, $params, $user->getLogin());
            // Fix html
            $fixed = $this->fixShit($response);
            // Load dom
            $this->loadDomS($fixed);

            $this->logger->info('Get grades invoked by User', $user->jsonSerialize());
            // Session expired
            $this->renewSessionIfNeeded($user, 'getGrades', $user);

            // Get lesson's names
            $lessons = [];

            foreach ($this->dom->find('tr.dataRowExport') as $rawLessonName) {
                /** @var HtmlNode $rawLessonName */
                $name = $rawLessonName->find('td')[0]->text;
                $a = new stdClass();
                $a->name = html_entity_decode(trim($name));
                $a->primePeriod = [];
                $a->latterPeriod = [];

                array_push($lessons, $a);
            }

            // Now get each subject separately
            for ($i = 0;  $i < count($lessons); $i++) {
                $response = $this->curl->get('https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_oceny_ucznia_szczegoly.pl', [
                    'print_version' => 1,
                    'zajecia' => $lessons[$i]->name,
                    'login_ucznia' => ($user->getChildLogin()) ? ($user->getChildLogin()) : ($user->getLogin()),
                ], $user->getLogin());

                if ($i == 0) {
                    // Different order for only 1st :c
                    $response = $this->curl->get('https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_oceny_ucznia_szczegoly.pl', [
                        'print_version' => 1,
                        'zajecia' => $lessons[$i]->name,
                        'login_ucznia' => ($user->getChildLogin()) ? ($user->getChildLogin()) : ($user->getLogin()),
                    ], $user->getLogin());
                }

                // Fix html
                $fixed = html_entity_decode($this->fixShit($response));
                // Load dom
                $this->loadDomS($fixed);

                foreach ($this->dom->find('tr.dataRowExport') as $gradeRaw) {
                    /** @var HtmlNode $gradeRaw */
                    $grade = new stdClass;
                    $grade->category = $this->mb_ucfirst(mb_strtolower(trim($gradeRaw->find('td')[0]->text), 'UTF-8'));
                    $tmp = $this->mb_ucfirst(mb_strtolower(trim($gradeRaw->find('td')[1]->text), 'UTF-8'));
                    if (!empty($tmp)) {
                        $grade->category .= ' '.$tmp;
                    }
                    $grade->grade = trim($gradeRaw->find('td')[2]->text);
                    $grade->value = trim($gradeRaw->find('td')[3]->text);
                    $grade->weight = (int) trim($gradeRaw->find('td')[4]->text);
                    $grade->period = (int) trim($gradeRaw->find('td')[5]->text);
                    $grade->average = (trim($gradeRaw->find('td')[6]->text) == "Tak") ? true : false;
                    $grade->individual = (trim($gradeRaw->find('td')[7]->text) == "Tak") ? true : false;
                    $grade->description = trim($gradeRaw->find('td')[8]->text);
                    $grade->date = substr(trim($gradeRaw->find('td')[9]->innerHtml), 6, -7);
                    $grade->issuer = ucwords(mb_strtolower(trim($gradeRaw->find('td')[10]->text), 'UTF-8'));

                    if ($grade->period == 1) {
                        array_push($lessons[$i]->primePeriod, $grade);
                    } else {
                        array_push($lessons[$i]->latterPeriod, $grade);
                    }

                    // Were needed raw in url
                    $lessons[$i]->name = $this->mb_ucfirst(mb_strtolower($lessons[$i]->name , 'UTF-8'));
                }
            }

            $r->grades = $lessons;
            return $r;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (WrongPasswordOrLoginException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws WrongPasswordOrLoginException
     */
    public function getExams(User $user): stdClass {
        try {
            $r = new stdClass();
            // First we gonna act like already logged in user
            $url = 'https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_planowane_zadania.pl';

            $params = ['print_version' => 1];

            $response = $this->curl->get($url, $params, $user->getLogin());
            // Fix html
            $fixed = html_entity_decode($this->fixShit($response));
            // Load dom
            $this->loadDomS($fixed);
            $this->logger->info('Get exams invoked by User', $user->jsonSerialize());

            // Session expired
            $this->renewSessionIfNeeded($user, 'getExams', $user);

            $exams = [];

            foreach ($this->dom->find('tr.dataRowExport') as $rawExercise) {
                $exam = new stdClass;
                $exam->school = ucwords(mb_strtolower(trim($rawExercise->find('td')[0]->text), 'UTF-8'));
                $exam->group = ucwords(mb_strtolower(trim($rawExercise->find('td')[1]->text), 'UTF-8'));
                $exam->category = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[2]->text), 'UTF-8'));
                $exam->type = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[3]->text), 'UTF-8'));
                $exam->loaction = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[4]->text), 'UTF-8'));
                $exam->lesson = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[5]->text), 'UTF-8'));
                $exam->subject = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[6]->text), 'UTF-8'));
                $exam->target = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[7]->text), 'UTF-8'));
                $exam->info = $this->mb_ucfirst(mb_strtolower(trim($rawExercise->find('td')[8]->text), 'UTF-8'));
                $date = substr(trim($rawExercise->find('td')[9]->innerHtml), 6, -7);
                $dtmp = DateTime::createFromFormat('d/m/y G:i:s', $date);
                $exam->dateStart = $dtmp->format('D M d Y H:i:s O');
                $date = substr(trim($rawExercise->find('td')[10]->innerHtml), 6, -7);
                $dtmp = DateTime::createFromFormat('d/m/y G:i:s', $date);
                $exam->dateEnd = $dtmp->format('D M d Y H:i:s O');
                $exam->dateAdded = substr(trim($rawExercise->find('td')[11]->innerHtml), 6, -7);
                $exam->issuer = ucwords(mb_strtolower(trim($rawExercise->find('td')[12]->text), 'UTF-8'));

                array_push($exams, $exam);
            }

            $r->exams = $exams;
            return $r;
        } catch (WrongPasswordOrLoginException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param $user User
     * @return stdClass
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws WrongPasswordOrLoginException
     */
    public function getHomeworks(User $user): stdClass {
        try {
            $r = new stdClass();

            // First we gonna act like already logged in user
            $url = 'https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_prace_domowe_ucznia.pl';

            $params = ['print_version' => 1];

            $response = $this->curl->get($url, $params, $user->getLogin());
            // Fix html
            $fixed = html_entity_decode($this->fixShit($response));
            // Load dom
            $this->loadDomS($fixed);
            $this->logger->info('Get homeworks invoked by User', $user->jsonSerialize());

            // Session expired
            $this->renewSessionIfNeeded($user, 'getExams', $user);

            $homeworks = [];

            foreach ($this->dom->find('tr.dataRowExport') as $rawHomework) {
                $homework = new stdClass;
                $homework->school = ucwords(mb_strtolower(trim($rawHomework->find('td')[0]->text), 'UTF-8'));
                $homework->group = ucwords(mb_strtolower(trim($rawHomework->find('td')[1]->text), 'UTF-8'));
                $homework->lesson = $this->mb_ucfirst(mb_strtolower(trim($rawHomework->find('td')[2]->text), 'UTF-8'));
                $homework->info = $this->mb_ucfirst(mb_strtolower(trim($rawHomework->find('td')[3]->text), 'UTF-8'));
                $date = substr(trim($rawHomework->find('td')[4]->innerHtml), 6, -7);
                $dtmp = DateTime::createFromFormat('d/m/y G:i:s', $date);
                $homework->dateEnd = $dtmp->format('D M d Y H:i:s O');

                array_push($homeworks, $homework);
            }

            $r->homeworks = $homeworks;
            return $r;
        } catch (WrongPasswordOrLoginException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param User $user
     * @return stdClass
     * @throws ChildNotFoundException
     * @throws CircularException
     * @throws NotLoadedException
     * @throws StrictException
     * @throws WrongPasswordOrLoginException
     */
    public function getSubjects(User $user) {
        try {
            $r = new stdClass();

            $url = 'https://nasze.miasto.gdynia.pl/ed_miej/zest_ed_tematy_zajec.pl';

            $params = [
                'print_version' => 1,
                'f_g_start' => 0,
                'f_g_page_size_value' => 9999,
            ];

            $response = $this->curl->get($url, $params, $user->getLogin());
            // Fix html
            $fixed = html_entity_decode($this->fixShit($response));
            // Load dom
            $this->loadDomS($fixed);
            $this->logger->info('Get subjects invoked by User', $user->jsonSerialize());

            // Session expired
            $this->renewSessionIfNeeded($user, 'getSubjects', $user);

            $subjects = [];

            foreach ($this->dom->find('tr.dataRowExport') as $rawSubject) {
                $subject = new stdClass;
                $subject->school = ucwords(mb_strtolower(trim($rawSubject->find('td')[0]->text), 'UTF-8'));
                $subject->group = ucwords(mb_strtolower(trim($rawSubject->find('td')[1]->text), 'UTF-8'));
                $subject->season = ucwords(mb_strtolower(trim($rawSubject->find('td')[2]->text), 'UTF-8'));
                $subject->lesson = $this->mb_ucfirst(mb_strtolower(trim($rawSubject->find('td')[3]->text), 'UTF-8'));
                // Skip 4 "Nazwa w innym języku"
                if (mb_strtolower(trim($rawSubject->find('td')[5]->text), 'UTF-8') == 'tygodniowy') {
                    $subject->cycle = 'weekly';
                } else {
                    $subject->cycle = 'daily';
                }
                $date = substr(trim($rawSubject->find('td')[6]->innerHtml), 6, -7);
                $dtmp = DateTime::createFromFormat('d/m/y', $date);
                $subject->date = $dtmp->format('D M d Y H:i:s O');

                $date = substr(trim($rawSubject->find('td')[7]->innerHtml), 6, -7);
                $dtmp = DateTime::createFromFormat('d/m/y', $date);
                $subject->dateStart = $dtmp->format('D M d Y H:i:s O');

                $date = substr(trim($rawSubject->find('td')[8]->innerHtml), 6, -7);
                $dtmp = DateTime::createFromFormat('d/m/y', $date);
                $subject->dateEnd = $dtmp->format('D M d Y H:i:s O');

                $subject->dayInWeekName = $this->mb_ucfirst(mb_strtolower(trim($rawSubject->find('td')[9]->text), 'UTF-8'));

                $tmp = trim($rawSubject->find('td')[10]->text);

                if (strpos($tmp, ',') !== false) {
                    $parts = explode(',', $tmp, 2);
                    if (strpos($parts[0], '-') === false) {
                        $subject->lessonNumber = intval(trim($parts[0]));
                    } else {

                        $parts2 = explode('-', trim($parts[0]));
                        $start = intval($parts2[0]);
                        $stop = intval($parts2[1]);

                        if ($start == $stop) {
                            $subject->lessonNumber = $start;
                        } else {
                            $subject->lessonNumbers = [];

                            for ($i = $start; $i <= $stop; $i++) {
                                array_push($subject->lessonNumbers, $i);
                            }
                        }
                    }

                    if (array_key_exists(1, $parts)) {
                        $subject->value = $this->mb_ucfirst(mb_strtolower(trim($parts[1]), 'UTF-8'));
                    } else {
                        $subject->value = $this->mb_ucfirst(mb_strtolower(trim($tmp), 'UTF-8'));
                    }

                } else {
                    $subject->value = $this->mb_ucfirst(mb_strtolower(trim($tmp), 'UTF-8'));
                }

                array_push($subjects, $subject);
            }

            $r->subjects = $subjects;
            return $r;

        } catch (WrongPasswordOrLoginException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (CircularException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (StrictException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        } catch (NotLoadedException $e) {
            $this->logger->error($e->getMessage(), $e->getTrace());
            throw $e;
        }
    }

    /**
     * @param User $user
     * @return string|null
     * @throws WrongPasswordOrLoginException
     */
    public function getLastUpdate(User $user) {
        try {
            $params = [
                'user' => $user->getLogin(),
                'pass_md5' => $user->getPassMd5(),
                'action' => 'set',
            ];

            $response = $this->curl->post('https://nasze.miasto.gdynia.pl/ed_miej/login.pl', $params, $user->getLogin());

            if (strpos($response, 'Niepoprawna')) {
                $this->logger->notice('Access denied for User', $user->jsonSerialize());
                throw new WrongPasswordOrLoginException("Wrong password and/or login!");
            }

            $fixed = html_entity_decode($this->fixShit($response));
            $this->loadDomS($fixed);

            // META HTTP-EQUIV="Refresh" CONTENT="0;
            $sid = null;
            $meta = $this->dom->getElementsByTag('meta')[0];
            $sid = substr($meta->getAttribute('content'), 26, -10);

            $response = $this->curl->get('https://nasze.miasto.gdynia.pl/ed_miej/login_check.pl', [
                'sid' => $sid,
                'url_back' => 'https://nasze.miasto.gdynia.pl/ed_miej/zest_start.pl'
            ], $user->getLogin());

            $fixed = html_entity_decode($this->fixShit($response));
            $this->loadDomS($fixed);

            $userInfo = $this->dom->getElementById('userinfo');
            $lastUpdate = substr($userInfo->text, 26, -1);
            $dateTimeLastUpdate = DateTime::createFromFormat('Y-m-d H:i:s', $lastUpdate);
            $dateTimeLastUpdate->setTimeZone(new DateTimeZone('Europe/Warsaw'));

            return $dateTimeLastUpdate->format('D M d Y H:i:s O'); // JS format
        } catch (ChildNotFoundException $e) {
            $this->logger->error($e);
            return null;
        } catch (NotLoadedException $e) {
            $this->logger->error($e);
            return null;
        } catch (CircularException $e) {
            $this->logger->error($e);
            return null;
        } catch (StrictException $e) {
            $this->logger->error($e);
            return null;
        }
    }
}