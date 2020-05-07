<?php
declare(strict_types=1);

namespace App\Domain\User;

use JsonSerializable;

class User implements JsonSerializable {
    private $login;
    private $childLogin;
    private $passwordMd5;
    private $sid;

    /**
     * User constructor.
     * @param $login
     * @param $passwordMd5
     */
    public function __construct($login, $passwordMd5) {
        $this->login = $login;
        $this->passwordMd5 = $passwordMd5;
    }

    /**
     * @return array
     */
    public function jsonSerialize() {
        return [
            'login' => $this->login,
            'pass_length' => strlen($this->passwordMd5),
            'sid' => $this->sid,
        ];
    }

    /**
     * @return string
     */
    public function getLogin(): string {
        return $this->login;
    }

    /**
     * @return string
     */
    public function getPassMd5(): string {
        return $this->passwordMd5;
    }

    /**
     * @param string $sid
     */
    public function setSid($sid): void {
        $this->sid = $sid;
    }

    /**
     * @return string
     */
    public function getSid(): string {
        return $this->sid;
    }

    /**
     * @return mixed string | null
     */
    public function getChildLogin() {
        return $this->childLogin;
    }

    /**
     * @param string $childLogin
     */
    public function setChildLogin($childLogin): void {
        $this->childLogin = $childLogin;
    }
}
