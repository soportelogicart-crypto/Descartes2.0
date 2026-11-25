<?php

declare(strict_types=1);

namespace Descartes\Api\Tests;

use Descartes\Api\Config\EntityConfig;
use Descartes\Api\Services\Importacion\ImportacionFilaValidator;
use PHPUnit\Framework\TestCase;

final class ImportacionFilaValidatorTest extends TestCase
{
  /** @return array<string, mixed> */
  private function hoja(string $entidad): array
  {
    $hoja = ImportacionFilaValidator::hoja($entidad);
    $this->assertNotNull($hoja, "Falta la hoja {$entidad}");
    return $hoja;
  }

  public function testConfigCubreLasEntidadesEnOrdenDeDependencias(): void
  {
    $cfg = ImportacionFilaValidator::config();
    $this->assertSame(
      ['macrofamilias', 'familias', 'subfamilias', 'proveedores', 'articulos', 'clientes'],
      $cfg['orden']
    );
    foreach ($cfg['orden'] as $entidad) {
      $this->assertArrayHasKey($entidad, $cfg['hojas']);
    }
  }

  public function testLasColumnasExistenEnLaDefinicionDeLaEntidad(): void
  {
    $cfg = ImportacionFilaValidator::config();
    foreach ($cfg['hojas'] as $entidad => $hoja) {
      $entidadCfg = EntityConfig::get($entidad);
      $this->assertNotNull($entidadCfg, "Entidad {$entidad} no definida");
      $claves = [];
      foreach ($hoja['columnas'] as $col) {
        $this->assertArrayHasKey(
          $col['clave'],
          $entidadCfg['fields'],
          "{$entidad}: la columna {$col['clave']} no es un campo de la entidad"
        );
        $this->assertNotContains($col['cabecera'], $claves, "{$entidad}: cabecera repetida {$col['cabecera']}");
        $claves[] = $col['cabecera'];
      }
      $this->assertContains($hoja['claveCodigo'], array_column($hoja['columnas'], 'clave'));
    }
  }

  public function testObligatoriasDeCliente(): void
  {
    $r = ImportacionFilaValidator::validar($this->hoja('clientes'), ['direccion' => 'Calle 1']);
    $campos = array_column($r['errores'], 'campo');
    $this->assertEqualsCanonicalizing(['nombre', 'nif', 'formaPago'], $campos);
  }

  public function testObligatoriasDeMacrofamiliaYSubfamilia(): void
  {
    $macro = ImportacionFilaValidator::validar($this->hoja('macrofamilias'), ['codigo' => '1']);
    $this->assertSame(['descripcion'], array_column($macro['errores'], 'campo'));

    $sub = ImportacionFilaValidator::validar($this->hoja('subfamilias'), ['descripcion' => 'Cactus']);
    $this->assertEqualsCanonicalizing(['codigo', 'familiaCodigo'], array_column($sub['errores'], 'campo'));
  }

  public function testCodigoVacioDeClienteNoEsErrorAqui(): void
  {
    $r = ImportacionFilaValidator::validar($this->hoja('clientes'), [
      'nombre' => 'Maria', 'nif' => '12345678Z', 'formaPago' => '01',
    ]);
    $this->assertSame([], $r['errores']);
    $this->assertArrayNotHasKey('codigo', $r['datos']);
  }

  public function testLongitudMaximaDeColumna(): void
  {
    $r = ImportacionFilaValidator::validar($this->hoja('familias'), [
      'codigo' => '1234567', 'descripcion' => 'Plantas', 'macroFamiliaCodigo' => '01',
    ]);
    $this->assertCount(1, $r['errores']);
    $this->assertSame('codigo', $r['errores'][0]['campo']);
  }

  public function testLimiteDeBaseDeDatosMasEstrictoQueElDeLaConfiguracion(): void
  {
    $r = ImportacionFilaValidator::validar(
      $this->hoja('clientes'),
      ['nombre' => 'Maria Perez', 'nif' => '12345678Z', 'formaPago' => '01'],
      ['nombre' => ['max' => 5]]
    );
    $this->assertSame('nombre', $r['errores'][0]['campo']);
  }

  public function testColumnaNumericaEnBaseDeDatosRechazaTexto(): void
  {
    $limites = ['codigo' => ['tipo' => 'int', 'min' => -32768, 'max' => 32767]];
    $hoja = $this->hoja('familias');
    $mal = ImportacionFilaValidator::validar(
      $hoja,
      ['codigo' => 'ABC', 'descripcion' => 'X', 'macroFamiliaCodigo' => '1'],
      $limites
    );
    $this->assertSame('codigo', $mal['errores'][0]['campo']);

    $grande = ImportacionFilaValidator::validar(
      $hoja,
      ['codigo' => '40000', 'descripcion' => 'X', 'macroFamiliaCodigo' => '1'],
      $limites
    );
    $this->assertSame('codigo', $grande['errores'][0]['campo']);

    $bien = ImportacionFilaValidator::validar(
      $hoja,
      ['codigo' => '001', 'descripcion' => 'X', 'macroFamiliaCodigo' => '1'],
      $limites
    );
    $this->assertSame([], $bien['errores']);
    $this->assertSame('001', $bien['datos']['codigo']);
  }

  public function testPreciosAceptanComaYPunto(): void
  {
    $this->assertSame(12.5, ImportacionFilaValidator::numeroDecimal('12,5'));
    $this->assertSame(12.5, ImportacionFilaValidator::numeroDecimal('12.5'));
    $this->assertSame(1234.56, ImportacionFilaValidator::numeroDecimal('1.234,56'));
    $this->assertSame(1234.56, ImportacionFilaValidator::numeroDecimal('1,234.56'));
    $this->assertNull(ImportacionFilaValidator::numeroDecimal('abc'));
  }

  public function testPrecioNoNumericoYNegativo(): void
  {
    $base = [
      'descripcion' => 'Ficus', 'familia' => '1', 'subfamilia' => '1', 'agrupacion' => '1',
      'proveedorHabitual' => '1', 'impuestoCodigo' => '01',
    ];
    $hoja = $this->hoja('articulos');
    $a = ImportacionFilaValidator::validar($hoja, $base + ['precioVen1' => 'mucho']);
    $this->assertSame('precioVen1', $a['errores'][0]['campo']);
    $b = ImportacionFilaValidator::validar($hoja, $base + ['precioVen1' => '-3']);
    $this->assertSame('precioVen1', $b['errores'][0]['campo']);
    $c = ImportacionFilaValidator::validar($hoja, $base + ['precioVen1' => '9,95']);
    $this->assertSame([], $c['errores']);
    $this->assertSame(9.95, $c['datos']['precioVen1']);
  }

  public function testCeldasNumericasDeExcelSinDecimales(): void
  {
    $this->assertSame('25', ImportacionFilaValidator::textoCelda(25.0));
    $this->assertSame('25', ImportacionFilaValidator::textoCelda(25));
    $this->assertSame('3.5', ImportacionFilaValidator::textoCelda(3.5));
    $this->assertSame('', ImportacionFilaValidator::textoCelda(null));
    $this->assertSame('hola', ImportacionFilaValidator::textoCelda('  hola '));
  }

  public function testCabecerasSeComparanSinTildesNiAsterisco(): void
  {
    $this->assertSame('razon social', ImportacionFilaValidator::normalizarCabecera('Razón social *'));
    $this->assertSame('codigo postal', ImportacionFilaValidator::normalizarCabecera(' Código  postal'));
  }

  public function testCodigosSeComparanSinCerosAIzquierda(): void
  {
    $this->assertSame(ImportacionFilaValidator::normalizarCodigo('001'), ImportacionFilaValidator::normalizarCodigo('1'));
    $this->assertSame('0', ImportacionFilaValidator::normalizarCodigo('000'));
    $this->assertSame('ab1', ImportacionFilaValidator::normalizarCodigo(' AB1 '));
  }
}
