<?php
declare(strict_types=1);

namespace PublicWhip\Web;

use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use PublicWhip\Web\Controllers\DebugBarController;
use PublicWhip\Web\Controllers\DivisionController;
use PublicWhip\Web\Controllers\DocsController;
use PublicWhip\Web\Controllers\IndexController;
use PublicWhip\Web\Controllers\PingController;
use Slim\App;
use Slim\Http\Response;

/**
 * Class Routing.
 *
 * Setup the routing.
 */
class Routing
{

    /**
     * Setup all the routing.
     *
     * @param App $app Slim App.
     */
    public function getRouting(App $app)
    {

        // index page
        $app->get('/', [IndexController::class, 'indexAction']);

        // divisions
        $this->setupDivisions($app);
        $this->setupPingRoutes($app);

        $app->get('/docs/{file:.*}', [DocsController::class, 'render']);
        /**
         * Register the debugbar only if we are in development/debug mode,
         */
        if (!$app->getContainer()->get('settings.debug')) {
            return;
        }
        $app->get('/debugbar/[{filePath:.*}]', [DebugBarController::class, 'staticFileAction']);
    }

    /**
     * Handle optional trailing slashes on GET requests.
     *
     * @param App $app Slim App.
     * @return void
     */
    public function setupTrailingSlash(App $app): void
    {
        $container = $app->getContainer();
        $app->add(
            function (RequestInterface $request, Response $response, callable $next) use ($container) {
                $uri = $request->getUri();
                $path = $uri->getPath();
                if (\strlen($path) > 1) {
                    if ('/' !== substr($path, -1) && !pathinfo($path, PATHINFO_EXTENSION)) {
                        $path .= '/';
                    }
                } elseif ('' === $path) {
                    $path = '/';
                }
                $new = $uri->withPath($path);
                if ($uri->getPath() !== $path) {
                    $logger = $container->get(LoggerInterface::class);
                    $logger->debug('Redirecting from ' . $uri . ' to ' . $new);
                    if ('GET' === $request->getMethod()) {
                        return $response->withRedirect($new, 301);
                    }
                }
                return $next($request->withUri($new), $response);
            }
        );
    }

    /**
     * Sets up all the routes starting /ping - mainly our uptime monitoring ones.
     *
     * @param App $app Slim app.
     *
     * @return void
     */
    private function setupPingRoutes(App $app): void
    {
        $app->group(
            '/ping',
            function (App $app): void {
                $app->get('/', [PingController::class, 'indexAction']);
                $app->get('/lastDivisionParsed/', [PingController::class, 'lastDivisionParsedAction']);
            }
        );
    }

    /**
     * Setup all the routes starting /divisions .
     *
     * @param App $app Slim App
     *
     * @return void
     */
    private function setupDivisions(App $app): void
    {
        $app->group(
            '/divisions',
            function (App $app): void {
                $app->get('/', [DivisionController::class, 'indexAction']);
                $app->get(
                    '/{divisionId:[0-9]+}/',
                    [DivisionController::class, 'showDivisionById']
                )->setName('divisionById');
                $app->get(
                    '/' .
                    '{house:commons|lords|scotland}/' .
                    '{date:18|19|20[0-9][0-9]\-[0-1][0-9]\-[0-3][0-9]}/' .
                    '{divisionNumber:[0-9]+}/',
                    [DivisionController::class, 'showDivisionByDateAndNumberAction']
                )->setName('divisionByHouseDateNumber');
            }
        );
    }
}