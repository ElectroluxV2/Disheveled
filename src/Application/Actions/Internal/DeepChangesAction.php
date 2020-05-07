<?php
declare(strict_types=1);

namespace App\Application\Actions\Internal;

use App\Application\Actions\Action;
use App\Domain\Diary\ChangeDetector;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use stdClass;

class DeepChangesAction extends Action {

    /**
     * @var ChangeDetector
     */
    private $changeDetector;

    public function __construct(LoggerInterface $logger, ChangeDetector $changeDetector) {
        parent::__construct($logger);
        $this->changeDetector = $changeDetector;
    }

    /**
     * @inheritDoc
     */
    protected function action(): Response {
        $r = new stdClass();
        $r->deepChanges = $this->changeDetector->deepChanges();

        return $this->respondWithData($r);
    }
}