<?php
declare(strict_types=1);

namespace App\Application\Actions\User;

use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;

class GetLessonPlanAction extends UserAction {

    /**
     * @return Response
     */
    protected function action(): Response {
        // UserManager will handle session even if its expired
        $user = $this->request->getAttribute('user');
        $result = $this->userManager->getLessonPlan($user);
        return $this->respondWithData($result);
    }
}