<?php

declare(strict_types=1);

namespace Sunnysideup\EcommerceMCP\Controllers;

use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;

class McpDiscoveryController extends Controller
{
    private static array $allowed_actions = ['index'];

    public function index(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isGET()) {
            $response = HTTPResponse::create('Method Not Allowed', 405);
            $response->addHeader('Allow', 'GET');

            return $response;
        }

        return Injector::inst()->get(McpController::class)->manifest();
    }
}
