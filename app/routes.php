<?php
declare(strict_types=1);

use App\Application\Actions\Internal\DeepChangesAction;
use App\Application\Actions\User\GetExamsAction;
use App\Application\Actions\User\GetGradesAction;
use App\Application\Actions\User\GetHomeworksAction;
use App\Application\Actions\User\GetLessonPlanAction;
use App\Application\Actions\User\GetSubjectsAction;
use App\Application\Actions\User\LoginUserAction;
use App\Application\Actions\User\EnablePushAction;
use App\Application\Actions\Internal\AnyChangesCheckAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    $container = $app->getContainer();

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->post('/login', LoginUserAction::class);
    $app->post('/lessonPlan', GetLessonPlanAction::class);
    $app->post('/grades', GetGradesAction::class);
    $app->post('/exams', GetExamsAction::class);
    $app->post('/homeworks', GetHomeworksAction::class);
    $app->post('/subjects', GetSubjectsAction::class);
    $app->post('/subscribe', EnablePushAction::class);

    $app->post('/anyChangesCheck', AnyChangesCheckAction::class);
    $app->post('/deepChangesCheck', DeepChangesAction::class);

    /*$app->group('/users', function (Group $group) use ($container) {
        $group->get('', ListUsersAction::class);
        $group->get('/{id}', ViewUserAction::class);
    });*/
};
