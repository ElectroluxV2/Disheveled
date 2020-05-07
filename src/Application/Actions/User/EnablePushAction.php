<?php
declare(strict_types=1);

namespace App\Application\Actions\User;

use Psr\Http\Message\ResponseInterface as Response;

class EnablePushAction extends UserAction {

    /**
     * @return Response
     */
    protected function action(): Response {

        $user = $this->request->getAttribute('user');

        $result = $this->userManager->saveCredentials($user);

        $subscription = $this->request->getAttribute('subscription');

        $result2 = $this->userManager->savePush($user, json_encode($subscription));

        return $this->respondWithData(array_merge($result, $result2));
    }
}