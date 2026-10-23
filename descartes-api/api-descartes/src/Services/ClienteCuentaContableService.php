<?php

declare(strict_types=1);

namespace Descartes\Api\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Alta de la cuenta contable del cliente en el plan contable ([Cuentas]).
 *
 * Convenio de la BD legacy: la cuenta del cliente es 430 + los 6 ultimos
 * digitos del codigo (000000921 → 430000921), cuelga del nivel 4300 y copia
 * los datos fiscales y de cobro de la ficha. [Clientes].[CuentaCtb2] es la
 * columna que enlaza cliente y cuenta (CuentaCtb esta sin usar).
 */
final class ClienteCuentaContableService
{
  private const NIVEL_PADRE = '4300';
  /** Niveles padre del plan por orden de preferencia, por si falta el 4300. */
  private const NIVELES_PADRE = ['4300', '430', '43'];

  private PDO $pdo;

  public function __construct(PDO $pdo)
  {
    $this->pdo = $pdo;
  }

  /**
   * Situacion de la cuenta del cliente: si ya la tiene, cual seria y si el
   * codigo esta libre. Lo usa la ficha para habilitar el boton de crearla.
   *
   * @return array<string, mixed>
   */
  public function estado(string $codigoCliente): array
  {
    $cliente = $this->cliente($codigoCliente);
    $actual = $this->cuentaDeCliente($cliente);
    $propuesta = $this->codigoCuenta((string) $cliente['Codigo']);
    $ocupadaPor = $actual === null ? $this->clienteConCuenta($propuesta, (string) $cliente['Codigo']) : null;

    return [
      'codigo' => rtrim((string) $cliente['Codigo']),
      'cuenta' => $actual,
      'tieneCuenta' => $actual !== null,
      'cuentaPropuesta' => $propuesta,
      'existeEnPlan' => $this->existeCuenta($propuesta),
      'ocupadaPor' => $ocupadaPor,
      'puedeCrear' => $actual === null && $ocupadaPor === null,
    ];
  }

  /**
   * Da de alta la cuenta 430xxxxxx del cliente y la enlaza en CuentaCtb2.
   * Si la cuenta ya existia en el plan (cliente reactivado, alta manual en
   * contabilidad) solo la enlaza, sin tocar sus datos ni sus acumulados.
   *
   * @return array<string, mixed>
   */
  public function crear(string $codigoCliente): array
  {
    $cliente = $this->cliente($codigoCliente);
    $codigo = rtrim((string) $cliente['Codigo']);

    if ($this->cuentaDeCliente($cliente) !== null) {
      throw new RuntimeException('El cliente ya tiene cuenta contable', 409);
    }

    $cuenta = $this->codigoCuenta($codigo);
    $ocupadaPor = $this->clienteConCuenta($cuenta, $codigo);
    if ($ocupadaPor !== null) {
      throw new RuntimeException("La cuenta {$cuenta} ya esta asignada al cliente {$ocupadaPor}", 409);
    }

    $yaEnPlan = $this->existeCuenta($cuenta);

    $this->pdo->beginTransaction();
    try {
      if (!$yaEnPlan) {
        $this->insertarCuenta($cuenta, $cliente);
      }
      $this->enlazarCliente($codigo, $cuenta);
      $this->pdo->commit();
    } catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }

    return [
      'codigo' => $codigo,
      'cuenta' => $cuenta,
      'creada' => !$yaEnPlan,
      'descripcion' => $this->textoCorto($cliente['RazonSocial'] ?? '', 40),
    ];
  }

  /** @return array<string, mixed> */
  private function cliente(string $codigoCliente): array
  {
    $codigo = trim($codigoCliente);
    if ($codigo === '') {
      throw new InvalidArgumentException('Codigo de cliente obligatorio');
    }

    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 [Codigo], [RazonSocial], [NIF], [Direccion], [Poblacion], [CodigoPostal],
              [Provincia], [Pais], [Telefono1], [Fax], [FormaPago], [DiaPago1], [DiaPago2],
              [Banco], [CuentaBancaria], [Swift], [IBAN], [ReferenciaMandato],
              [FechaFirmaMandato], [EmailFacturacion], [TratamientoFiscal], [CuentaCtb2]
       FROM [Clientes]
       WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => $codigo]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
      throw new InvalidArgumentException('Cliente no encontrado');
    }

    return $row;
  }

  /** Cuenta ya enlazada en la ficha, o null si el cliente no tiene. */
  private function cuentaDeCliente(array $cliente): ?string
  {
    $valor = (float) ($cliente['CuentaCtb2'] ?? 0);
    if ($valor == 0.0) {
      return null;
    }

    return (string) (int) $valor;
  }

  /** 430 + los 6 ultimos digitos del codigo de cliente. */
  private function codigoCuenta(string $codigoCliente): string
  {
    $digitos = preg_replace('/\D+/', '', $codigoCliente) ?? '';
    if ($digitos === '' || (int) $digitos === 0) {
      throw new InvalidArgumentException(
        'El codigo del cliente no tiene parte numerica: no se puede calcular la cuenta contable'
      );
    }

    return '430' . str_pad(substr($digitos, -6), 6, '0', STR_PAD_LEFT);
  }

  /** Otro cliente que ya tenga enlazada esa cuenta, o null. */
  private function clienteConCuenta(string $cuenta, string $excluir): ?string
  {
    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 RTRIM([Codigo]) AS Codigo
       FROM [Clientes]
       WHERE ISNULL([CuentaCtb2], 0) = CAST(:cuenta AS float)
         AND RTRIM([Codigo]) <> :excluir'
    );
    $stmt->execute(['cuenta' => $cuenta, 'excluir' => $excluir]);
    $codigo = $stmt->fetchColumn();

    return $codigo === false ? null : (string) $codigo;
  }

  private function existeCuenta(string $cuenta): bool
  {
    $stmt = $this->pdo->prepare('SELECT TOP 1 1 FROM [Cuentas] WHERE RTRIM([Codigo]) = :cuenta');
    $stmt->execute(['cuenta' => $cuenta]);

    return (bool) $stmt->fetchColumn();
  }

  /** @param array<string, mixed> $cliente */
  private function insertarCuenta(string $cuenta, array $cliente): void
  {
    $stmt = $this->pdo->prepare(
      'INSERT INTO [Cuentas]
        ([Codigo], [NivelAnterior], [Descripcion], [UltNivel], [DesgloseAnalitica], [CentroCoste],
         [TipoGestion], [Nif], [Direccion], [Poblacion], [CodPostal], [Provincia], [Pais],
         [Telefono], [Fax], [RecargoCli], [FormaPago], [DiaPago1], [DiaPago2], [Banco],
         [CtaBancaria], [Tipo], [Calculo], [AcumAntDebe], [AcumAntHaber], [AcumDebe], [AcumHaber],
         [CuentaDestino], [TratamientoFiscal], [Swift], [IBAN], [ReferenciaMandato],
         [FechaFirmaMandato], [EmailFacturacion], [Asegurado], [RiesgoConcedido])
       VALUES
        (:codigo, :nivelAnterior, :descripcion, 1, 0, \' \',
         \'P\', :nif, :direccion, :poblacion, :codPostal, :provincia, :pais,
         :telefono, :fax, 0, :formaPago, :diaPago1, :diaPago2, :banco,
         :ctaBancaria, :tipo, 0, 0, 0, 0, 0,
         :cuentaDestino, :tratamientoFiscal, :swift, :iban, :referenciaMandato,
         :fechaFirmaMandato, :emailFacturacion, 0, 0)'
    );

    $stmt->execute([
      'codigo' => $cuenta,
      'nivelAnterior' => $this->nivelPadre(),
      'descripcion' => $this->textoCorto($cliente['RazonSocial'] ?? '', 40),
      'nif' => $this->textoCorto($cliente['NIF'] ?? '', 16),
      'direccion' => $this->textoCorto($cliente['Direccion'] ?? '', 40),
      'poblacion' => $this->textoCorto($cliente['Poblacion'] ?? '', 40),
      'codPostal' => $this->textoCorto($cliente['CodigoPostal'] ?? '', 6),
      'provincia' => $this->textoCorto($cliente['Provincia'] ?? '', 20),
      'pais' => $this->textoCorto($cliente['Pais'] ?? '', 40),
      'telefono' => $this->textoCorto($cliente['Telefono1'] ?? '', 12),
      'fax' => $this->textoCorto($cliente['Fax'] ?? '', 12),
      'formaPago' => $this->textoCorto($cliente['FormaPago'] ?? '', 3),
      'diaPago1' => $this->entero($cliente['DiaPago1'] ?? null),
      'diaPago2' => $this->entero($cliente['DiaPago2'] ?? null),
      'banco' => $this->textoCorto($cliente['Banco'] ?? '', 30),
      'ctaBancaria' => $this->textoCorto($cliente['CuentaBancaria'] ?? '', 20),
      'tipo' => $this->tipoDeFormaPago($this->textoCorto($cliente['FormaPago'] ?? '', 3)),
      'cuentaDestino' => $cuenta,
      'tratamientoFiscal' => $this->textoCorto($cliente['TratamientoFiscal'] ?? '', 1),
      'swift' => $this->textoCorto($cliente['Swift'] ?? '', 20),
      'iban' => $this->textoCorto($cliente['IBAN'] ?? '', 34),
      'referenciaMandato' => $this->textoCorto($cliente['ReferenciaMandato'] ?? '', 35),
      'fechaFirmaMandato' => $this->fecha($cliente['FechaFirmaMandato'] ?? null),
      'emailFacturacion' => $this->textoCorto($cliente['EmailFacturacion'] ?? '', 200),
    ]);
  }

  private function enlazarCliente(string $codigoCliente, string $cuenta): void
  {
    $stmt = $this->pdo->prepare(
      'UPDATE [Clientes] SET [CuentaCtb2] = CAST(:cuenta AS float) WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['cuenta' => $cuenta, 'codigo' => $codigoCliente]);
  }

  /** El nivel del que cuelga la cuenta: 4300 salvo que ese nivel no exista. */
  private function nivelPadre(): string
  {
    foreach (self::NIVELES_PADRE as $nivel) {
      if ($this->existeCuenta($nivel)) {
        return $nivel;
      }
    }

    return self::NIVEL_PADRE;
  }

  /** [Cuentas].[Tipo] replica el tipo de la forma de pago del cliente (R, T, X…). */
  private function tipoDeFormaPago(?string $formaPago): ?string
  {
    if ($formaPago === null || $formaPago === '') {
      return null;
    }

    $stmt = $this->pdo->prepare(
      'SELECT TOP 1 [Tipo] FROM [FormasPago] WHERE RTRIM([Codigo]) = :codigo'
    );
    $stmt->execute(['codigo' => $formaPago]);
    $tipo = $stmt->fetchColumn();
    if ($tipo === false || $tipo === null) {
      return null;
    }

    return $this->textoCorto($tipo, 1);
  }

  private function textoCorto(mixed $valor, int $max): ?string
  {
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '') {
      return null;
    }

    return mb_substr($texto, 0, $max);
  }

  private function entero(mixed $valor): int
  {
    return (int) round((float) ($valor ?? 0));
  }

  private function fecha(mixed $valor): ?string
  {
    $texto = trim((string) ($valor ?? ''));
    if ($texto === '') {
      return null;
    }
    $ts = strtotime($texto);

    return $ts === false ? null : date('Y-m-d H:i:s', $ts);
  }
}
