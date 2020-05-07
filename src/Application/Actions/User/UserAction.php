<?php
declare(strict_types=1);

namespace App\Application\Actions\User;

use App\Application\Actions\Action;
use App\Domain\User\UserManager;
use Psr\Log\LoggerInterface;

abstract class UserAction extends Action {
    /**
     * @var UserManager
     */
    protected $userManager;

    /**
     * @param LoggerInterface $logger
     * @param UserManager $userManager
     */
    public function __construct(LoggerInterface $logger, UserManager $userManager)
    {
        parent::__construct($logger);
        $this->userManager = $userManager;
    }
}
