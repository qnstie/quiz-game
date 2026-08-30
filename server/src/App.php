<?php

declare(strict_types=1);

namespace FamilyQuiz;

use DI\ContainerBuilder;
use FamilyQuiz\Db\Connections;
use FamilyQuiz\Middleware\AdminAuthMiddleware;
use FamilyQuiz\Middleware\CorsMiddleware;
use FamilyQuiz\Middleware\CsrfMiddleware;
use FamilyQuiz\Middleware\ParticipantAuthMiddleware;
use FamilyQuiz\Middleware\SecurityHeadersMiddleware;
use FamilyQuiz\Repo\AnswersRepo;
use FamilyQuiz\Repo\MediaRepo;
use FamilyQuiz\Repo\OptionsRepo;
use FamilyQuiz\Repo\ProjectsRepo;
use FamilyQuiz\Repo\QuizzesRepo;
use FamilyQuiz\Repo\ResultsRepo;
use FamilyQuiz\Repo\SuperusersRepo;
use FamilyQuiz\Repo\UsersRepo;
use FamilyQuiz\Routes\AdminRoutes;
use FamilyQuiz\Routes\AgentRoutes;
use FamilyQuiz\Routes\PublicRoutes;
use FamilyQuiz\Services\AuthService;
use FamilyQuiz\Services\ExportService;
use FamilyQuiz\Services\LockedException;
use FamilyQuiz\Services\MediaService;
use FamilyQuiz\Services\SanitizerService;
use FamilyQuiz\Services\ScoringService;
use FamilyQuiz\Services\SeedService;
use FamilyQuiz\Services\StateService;
use FamilyQuiz\Support\ConfigBag;
use FamilyQuiz\Support\IframeSanitizer;
use FamilyQuiz\Support\JsonResponse;
use Slim\Factory\AppFactory;

final class App
{
    public static function create(?string $configPath = null): \Slim\App
    {
        $configPath ??= dirname(__DIR__) . '/config.php';
        if (!is_file($configPath)) {
            $example = dirname(__DIR__) . '/config.php.example';
            if (is_file($example)) {
                copy($example, $configPath);
            }
        }
        /** @var array $config */
        $config = require $configPath;

        if (($config['app_env'] ?? '') === 'production') {
            $secret = (string) ($config['jwt_secret'] ?? '');
            if ($secret === '' || str_contains($secret, 'GENERATE_WITH') || strlen($secret) < 32) {
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode(['error' => ['code' => 'MISCONFIGURED', 'message' => 'jwt_secret must be set']]);
                exit;
            }
        }

        if (str_contains((string) $config['jwt_secret'], 'GENERATE_WITH') || strlen((string) $config['jwt_secret']) < 32) {
            $config['jwt_secret'] = 'local-dev-secret-' . str_repeat('x', 32);
        }

        Connections::configure($config);
        $configBag = new ConfigBag($config);

        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            ConfigBag::class => $configBag,
            SeedService::class => \DI\autowire(),
            AuthService::class => \DI\autowire()->constructorParameter('config', $config),
            ProjectsRepo::class => \DI\autowire(),
            SuperusersRepo::class => \DI\autowire(),
            QuizzesRepo::class => \DI\autowire(),
            OptionsRepo::class => \DI\autowire(),
            UsersRepo::class => \DI\autowire(),
            AnswersRepo::class => \DI\autowire(),
            MediaRepo::class => \DI\autowire(),
            ResultsRepo::class => \DI\autowire(),
            IframeSanitizer::class => \DI\autowire(),
            SanitizerService::class => \DI\autowire(),
            ScoringService::class => \DI\autowire(),
            StateService::class => \DI\autowire(),
            MediaService::class => \DI\autowire()->constructorParameter('config', $config),
            ExportService::class => \DI\autowire(),
            AdminAuthMiddleware::class => \DI\autowire(),
            ParticipantAuthMiddleware::class => \DI\autowire(),
        ]);

        $container = $builder->build();
        $app = \DI\Bridge\Slim\Bridge::create($container);

        $basePath = $configBag->pathPrefix();
        if ($basePath !== '') {
            $app->setBasePath($basePath);
        }

        $app->addBodyParsingMiddleware();

        $origins = $config['cors_origins'] ?? ['http://localhost:5173'];
        // Middleware is LIFO: last added runs first
        $app->add(new SecurityHeadersMiddleware());
        $app->add(new CsrfMiddleware($origins));
        $app->add(new CorsMiddleware($origins, (string) ($config['cookie_domain'] ?? '')));
        $app->add($container->get(ParticipantAuthMiddleware::class));

        $errorMiddleware = $app->addErrorMiddleware(
            ($config['app_env'] ?? '') !== 'production',
            true,
            true
        );
        $errorMiddleware->setDefaultErrorHandler(function ($request, \Throwable $e) use ($config) {
            if ($e instanceof LockedException) {
                return JsonResponse::error('LOCKED', 'Locked', 423, ['currentState' => $e->currentState]);
            }
            error_log($e->getMessage() . "\n" . $e->getTraceAsString());
            $message = ($config['app_env'] ?? '') === 'production' ? 'Internal error' : $e->getMessage();
            return JsonResponse::error('INTERNAL', $message, 500);
        });

        $seed = $container->get(SeedService::class);
        $seed->ensureSeedAdmin($config);
        if ($seed->seedPasswordStillInUse($config)) {
            error_log('WARNING: seed admin password still in use — change it in admin UI');
        }

        PublicRoutes::register($app);
        AdminRoutes::register($app, $container->get(AdminAuthMiddleware::class));
        AgentRoutes::register($app, $configBag);

        return $app;
    }
}
