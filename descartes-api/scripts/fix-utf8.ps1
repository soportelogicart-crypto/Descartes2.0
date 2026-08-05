# Fuerza UTF-8 sin BOM en archivos PHP/Apache que a veces quedan en UTF-16.
$utf8 = New-Object System.Text.UTF8Encoding $false
$apiRoot = Join-Path $PSScriptRoot '..'

function Write-Utf8File([string]$path, [string]$content) {
  [System.IO.File]::WriteAllText($path, $content, $utf8)
}

Write-Utf8File (Join-Path $apiRoot 'public\.htaccess') @'
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteCond %{REQUEST_FILENAME} !-d
  RewriteRule ^ index.php [QSA,L]
</IfModule>
'@

Write-Utf8File (Join-Path $apiRoot 'src\bootstrap.php') (Get-Content -Raw (Join-Path $apiRoot 'src\bootstrap.php'))

# bootstrap may still be UTF-16; rewrite from CorsMiddleware sibling pattern by reading via .NET with Unicode detection
$bootstrapPath = Join-Path $apiRoot 'src\bootstrap.php'
$bootstrapText = @'
<?php

declare(strict_types=1);

use Descartes\Api\Middleware\ClienteDbMiddleware;
use Descartes\Api\Middleware\CorsMiddleware;
use Descartes\Api\Middleware\SessionMiddleware;
use Descartes\Api\RequestContext;
use Descartes\Api\Repositories\ArtPreciosRepository;
use Descartes\Api\Repositories\ArticuloStockRepository;
use Descartes\Api\Repositories\EmpresaClienteRepository;
use Descartes\Api\Repositories\RolPermisoRepository;
use Descartes\Api\Repositories\RolRepository;
use Descartes\Api\Services\ArticuloService;
use Descartes\Api\Services\DependencyCheckService;
use Descartes\Api\Services\TiendaAlmacenService;
use Descartes\Api\Services\EmpresaClienteService;
use Descartes\Api\Services\MantenimientoService;
use Descartes\Api\Services\PermissionService;
use Descartes\Api\Services\RolService;
use Psr\Container\ContainerInterface;
use Slim\App;

return function (App $app): void {
  $container = $app->getContainer();

  $container->set(PDO::class, static fn () => RequestContext::getPdo());

  $container->set(PermissionService::class, static fn (ContainerInterface $c) => new PermissionService($c->get(PDO::class)));

  $container->set(DependencyCheckService::class, static fn (ContainerInterface $c) => new DependencyCheckService($c->get(PDO::class)));

  $container->set(TiendaAlmacenService::class, static fn (ContainerInterface $c) => new TiendaAlmacenService($c->get(PDO::class)));

  $container->set(ArtPreciosRepository::class, static fn (ContainerInterface $c) => new ArtPreciosRepository($c->get(PDO::class)));
  $container->set(ArticuloStockRepository::class, static fn (ContainerInterface $c) => new ArticuloStockRepository($c->get(PDO::class)));
  $container->set(ArticuloService::class, static fn (ContainerInterface $c) => new ArticuloService(
    $c->get(PDO::class),
    $c->get(ArtPreciosRepository::class),
    $c->get(ArticuloStockRepository::class)
  ));

  $container->set(MantenimientoService::class, static fn (ContainerInterface $c) => new MantenimientoService(
    $c->get(PDO::class),
    $c->get(DependencyCheckService::class),
    $c->get(TiendaAlmacenService::class),
    $c->get(ArticuloService::class)
  ));

  $container->set(EmpresaClienteRepository::class, static fn (ContainerInterface $c) => new EmpresaClienteRepository($c->get(PDO::class)));

  $container->set(EmpresaClienteService::class, static fn (ContainerInterface $c) => new EmpresaClienteService(
    $c->get(EmpresaClienteRepository::class)
  ));

  $container->set(RolRepository::class, static fn (ContainerInterface $c) => new RolRepository($c->get(PDO::class)));
  $container->set(RolPermisoRepository::class, static fn (ContainerInterface $c) => new RolPermisoRepository($c->get(PDO::class)));
  $container->set(RolService::class, static fn (ContainerInterface $c) => new RolService(
    $c->get(RolRepository::class),
    $c->get(RolPermisoRepository::class),
    $c->get(DependencyCheckService::class)
  ));

  $app->addRoutingMiddleware();
  $app->addBodyParsingMiddleware();
  $errorMiddleware = $app->addErrorMiddleware(true, true, true);
  $errorMiddleware->setDefaultErrorHandler(
    [\Descartes\Api\Http\ApiErrorHandler::class, 'handle']
  );

  $app->add(ClienteDbMiddleware::class);
  $app->add(SessionMiddleware::class);
  $app->add(CorsMiddleware::class);
};
'@
Write-Utf8File $bootstrapPath $bootstrapText

Write-Host 'UTF-8 fix applied in workspace.'
