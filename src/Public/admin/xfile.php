<?php

use XcVm\Public\Controllers\Api\FileTicketController;

/**
 * `/xfile`: a file another server reads from this one with a file ticket
 * (FileTicketController). nginx routes it through the admin gateway, as it
 * routes the relay endpoints.
 *
 * @package XC_VM_Web_Admin
 */

(new FileTicketController())->handle();
exit();
