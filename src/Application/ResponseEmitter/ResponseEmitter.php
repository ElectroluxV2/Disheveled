<?php
declare(strict_types=1);

namespace App\Application\ResponseEmitter;

use Psr\Http\Message\ResponseInterface;
use Slim\ResponseEmitter as SlimResponseEmitter;

class ResponseEmitter extends SlimResponseEmitter
{
    /**
     * {@inheritdoc}
     */
    public function emit(ResponseInterface $response): void {
        $acceptLocalhost = (((isset($_SERVER['HTTP_ORIGIN'])) && ($_SERVER['HTTP_ORIGIN']=='http://localhost:4200')) && ($_SERVER['REMOTE_ADDR']=='46.151.137.208'));
        // This variable should be set to the allowed host from which your API can be accessed with
        $origin = ($acceptLocalhost) ? 'http://localhost:4200' : 'https://edziennik.ga';

        $response = $response
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('X-Powered-By','Passion and commitment')
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization, Authentication')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->withAddedHeader('Cache-Control', 'post-check=0, pre-check=0')
            ->withHeader('Pragma', 'no-cache')
            ->withStatus(200);

        if (ob_get_contents()) {
            ob_clean();
        }

        parent::emit($response);
    }
}
