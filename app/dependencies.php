<?php
declare(strict_types=1);

use App\Domain\Utils\Encoding;
use DI\ContainerBuilder;
use Medoo\Medoo;
use Minishlink\WebPush\WebPush;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use PHPHtmlParser\Dom;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class Curl {

    private $curl;
    private $dRef;

    public function __construct($ssl, $ua, $ref) {
        $this->curl = curl_init();
        $this->dRef = $ref;

        curl_setopt($this->curl, CURLOPT_TIMEOUT, 3);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, $ssl);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, $ssl);
        curl_setopt($this->curl, CURLOPT_HEADER, true);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->curl, CURLOPT_USERAGENT, $ua);

    }

    public function get($url, $params, $userLogin, $ref = null) {

        if (empty($ref)) {
            $ref = $this->dRef;
        }

        $cookie = dirname(__FILE__).'/cookies/'.sha1($userLogin);


        curl_setopt($this->curl, CURLOPT_URL, $url.'?'.http_build_query($params));
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($this->curl, CURLOPT_REFERER, $ref);
        curl_setopt($this->curl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, $cookie);

        $result = curl_exec($this->curl);

        if (curl_errno($this->curl)) {
            return false;
        }

        return $result;
    }

    public function  post($url, $params, $userLogin, $ref = null) {

        if (empty($ref)) {
            $ref = $this->dRef;
        }

        $cookie = dirname(__FILE__).'/cookies/'.sha1($userLogin);

        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($this->curl, CURLOPT_REFERER, $ref);
        curl_setopt($this->curl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, $cookie);

        $result = curl_exec($this->curl);
        if (curl_errno($this->curl)) {
            return false;
        }

        return $result;
    }
}

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');

            $loggerSettings = $settings['logger'];
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },
    ], [
        Medoo::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');

            $databaseSettings = $settings['database'];

            $database = new Medoo([
                'database_type' => 'mysql',
                'database_name' => $databaseSettings['name'],
                'server' => $databaseSettings['host'],
                'username' => $databaseSettings['user'],
                'password' => $databaseSettings['pass'],
                'logging' => $databaseSettings['logs'],
                'charset' => 'UTF-8',
            ]);

            return $database;
        },
    ], [
        Dom::class => function (ContainerInterface $c) {
            $dom = new PHPHtmlParser\Dom;
            $dom->setOptions([
                'depthFirstSearch' => true,
                'cleanupInput' => false,
            ]);
            return $dom;
        }
    ],[
        Curl::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');
            $curlSettings = $settings['curl'];
            $curl = new Curl($curlSettings['sslVerify'], $curlSettings['userAgent'], $curlSettings['defaultReferer']);
            return $curl;
        },
    ], [
        WebPush::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');
            $auth = $settings['webPush'];
            $webPush = new WebPush($auth);
            return $webPush;
        }
    ]);
};
