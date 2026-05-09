<?php
declare(strict_types=1);

namespace App\Plugin\Remember\Hook;

use App\Controller\Base\View\UserPlugin;
use Kernel\Annotation\Hook;

class Main extends UserPlugin
{

    /**
     * @throws \Kernel\Exception\ViewException
     */
    #[Hook(point: \App\Consts\Hook::USER_VIEW_INDEX_BODY)]
    public function keep(): void
    {
        echo $this->render("memory", "Memory.html");
    }
}