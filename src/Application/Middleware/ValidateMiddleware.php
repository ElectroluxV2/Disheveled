<?php
declare(strict_types=1);

namespace App\Application\Middleware;

use App\Domain\DomainException\ArgumentNotFoundException;
use App\Domain\DomainException\IllegalArgumentException;
use App\Domain\User\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface as Middleware;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Exception\HttpBadRequestException;

class ValidateMiddleware implements Middleware {

    /**
     * Process an incoming server request.
     *
     * Processes an incoming server request in order to produce a response.
     * If unable to produce the response itself, it may delegate to the provided
     * request handler to do so.
     * @param Request $request
     * @param RequestHandler $handler
     * @return Response
     * @throws ArgumentNotFoundException
     * @throws HttpBadRequestException
     * @throws IllegalArgumentException
     */
    public function process(Request $request, RequestHandler $handler): Response {
        $input = json_decode(file_get_contents('php://input'));

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpBadRequestException($request, 'Malformed JSON input.');
        }

        $validKeys = [
            'login',
            'password_md5',
            'child',
            'subscription',
            'secret'
        ];

        $keys = get_object_vars($input);
        foreach ($keys as $key => $val) {
            if (!in_array($key, $validKeys)) {
                throw new IllegalArgumentException('Argument \''.$key.'\' is illegal.');
            }
        }

        if (($request->getUri()->getPath() === '/anyChangesCheck') || ($request->getUri()->getPath() === '/deepChangesCheck')) {
            if (empty($input->secret)) {
                throw new ArgumentNotFoundException('Secret argument not found!');
            } else if ($input->secret != '205296') {
                throw new ArgumentNotFoundException('Secret argument not found?');
            }

            return  $handler->handle($request);
        }

        if (empty($input->login)) {
            throw new ArgumentNotFoundException('Login argument not found!');
        }

        if (empty($input->password_md5)) {
            throw new ArgumentNotFoundException('PasswordMd5 argument not found!');
        }

        $user = new User($input->login, $input->password_md5);

        if ($request->getUri()->getPath() === '/subscribe') {

            if (empty($input->subscription)) {
                throw new ArgumentNotFoundException('Subscription argument not found!');
            }

            $request = $request->withAttribute('subscription', $input->subscription);
        }

        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION['sid'])) {
            $request = $request->withAttribute('sid', $_SESSION['sid']);
        }

        $sid = $request->getAttribute('sid');

        if (isset($sid)) {
            $user->setSid($sid);
        }
        // Remove sensitive information in case of data arg leak
        unset($input->login);
        if (isset($input->child)) {
            $user->setChildLogin($input->child);
            unset($input->child);
        }
        unset($input->password_md5);

        $request = $request->withAttribute('data', $input);
        $request = $request->withAttribute('user', $user);

        $response = $handler->handle($request);
        return $response;
    }
}