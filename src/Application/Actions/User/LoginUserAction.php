<?php
declare(strict_types=1);

namespace App\Application\Actions\User;

use Psr\Http\Message\ResponseInterface as Response;

class LoginUserAction extends UserAction {

    /**
     * @return Response
     */
    protected function action(): Response {

        $user = $this->request->getAttribute('user');

        $result = $this->userManager->login($user);

        return $this->respondWithData($result);
    }
}