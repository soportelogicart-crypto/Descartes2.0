<?php

declare(strict_types=1);

/**
 * Importacion de datos desde Excel: unica fuente de columnas (plantilla y validacion).
 *
 * Cada hoja = una entidad de mantenimiento. Las claves de columna son las mismas
 * que usa MantenimientoService::create() (campos API de entities.php).
 *
 * Columna:
 *  - clave:       campo API (entities.php)
 *  - cabecera:    texto de la cabecera en el Excel (sin tildes: se compara sin ellas)
 *  - obligatoria: la fila falla si va vacia
 *  - tipo:        texto | entero | decimal | booleano (Si/No)
 *  - max:         longitud maxima (texto)
 *  - ejemplo:     ejemplo para la hoja de instrucciones
 *  - ayuda:       explicacion corta
 *  - ref:         catalogo donde debe existir el valor (ver ImportacionService::REFERENCIAS)
 *
 * Hoja:
 *  - codigoAutomatico: el codigo puede ir vacio si la tienda tiene numeracion automatica
 *  - unicos:          otros campos que no pueden repetirse dentro del archivo (ademas del codigo)
 */
return [
  /** Orden de importacion (cada hoja depende de las anteriores). */
  'orden' => [
    'impuestos', 'formas-pago', 'macrofamilias', 'familias', 'subfamilias', 'proveedores', 'articulos', 'clientes',
  ],

  'hojas' => [
    'impuestos' => [
      'hoja' => 'Impuestos',
      'titulo' => 'Impuestos',
      'descripcion' => 'Tipos de IVA. Vaya primero en el archivo: los articulos los usan.',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => false,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 2, 'ejemplo' => 'IG', 'ayuda' => 'Codigo del impuesto (hasta 2 caracteres).',
        ],
        [
          'clave' => 'descripcion', 'cabecera' => 'Descripcion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 40, 'ejemplo' => 'IVA general', 'ayuda' => 'Nombre del impuesto.',
        ],
        [
          'clave' => 'porcentajeIVA', 'cabecera' => '% IVA', 'obligatoria' => true, 'tipo' => 'decimal',
          'max' => null, 'ejemplo' => '21', 'ayuda' => 'Porcentaje de IVA (0 si es exento).',
        ],
        [
          'clave' => 'porcentajeRec', 'cabecera' => '% Recargo', 'obligatoria' => false, 'tipo' => 'decimal',
          'max' => null, 'ejemplo' => '5,2', 'ayuda' => 'Recargo de equivalencia. Vacio = 0.',
        ],
        [
          'clave' => 'ivaExento', 'cabecera' => 'IVA exento', 'obligatoria' => false, 'tipo' => 'booleano',
          'max' => null, 'ejemplo' => 'No', 'ayuda' => 'Si o No. Vacio = No.',
        ],
        [
          'clave' => 'regimenEspecialAGYP', 'cabecera' => 'Regimen especial AGYP', 'obligatoria' => false,
          'tipo' => 'booleano', 'max' => null, 'ejemplo' => 'No',
          'ayuda' => 'Regimen especial agricultura, ganaderia y pesca. Si o No. Vacio = No.',
        ],
      ],
    ],

    'formas-pago' => [
      'hoja' => 'Formas de pago',
      'titulo' => 'Formas de pago',
      'descripcion' => 'Las usan los clientes y proveedores. Vaya al principio del archivo.',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => false,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 2, 'ejemplo' => 'EU', 'ayuda' => 'Codigo de la forma de pago (hasta 2 caracteres).',
        ],
        [
          'clave' => 'descripcion', 'cabecera' => 'Descripcion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 40, 'ejemplo' => 'Efectivo', 'ayuda' => 'Nombre de la forma de pago.',
        ],
        [
          'clave' => 'abreviacion', 'cabecera' => 'Abreviacion', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 3, 'ejemplo' => 'Eu', 'ayuda' => 'Hasta 3 caracteres.',
        ],
        [
          'clave' => 'cobroDeArqueo', 'cabecera' => 'Cobro de arqueo', 'obligatoria' => false, 'tipo' => 'booleano',
          'max' => null, 'ejemplo' => 'Si',
          'ayuda' => 'Si es un cobro al contado que entra en el arqueo de caja. Si o No. Vacio = No.',
        ],
        [
          'clave' => 'abrirCajon', 'cabecera' => 'Abrir cajon', 'obligatoria' => false, 'tipo' => 'booleano',
          'max' => null, 'ejemplo' => 'Si', 'ayuda' => 'Abre el cajon al cobrar. Si o No. Vacio = No.',
        ],
        [
          'clave' => 'numVtos', 'cabecera' => 'Num. vencimientos', 'obligatoria' => false, 'tipo' => 'entero',
          'max' => null, 'ejemplo' => '2', 'ayuda' => 'Numero de vencimientos. Vacio = 0 (pago al momento).',
        ],
        [
          'clave' => 'dias1erVto', 'cabecera' => 'Dias 1er vencimiento', 'obligatoria' => false, 'tipo' => 'entero',
          'max' => null, 'ejemplo' => '30', 'ayuda' => 'Dias hasta el primer vencimiento. Vacio = 0.',
        ],
        [
          'clave' => 'diasEntreVtos', 'cabecera' => 'Dias entre vencimientos', 'obligatoria' => false,
          'tipo' => 'entero', 'max' => null, 'ejemplo' => '30', 'ayuda' => 'Dias entre un vencimiento y el siguiente. Vacio = 0.',
        ],
      ],
    ],

    'macrofamilias' => [
      'hoja' => 'MacroFamilias',
      'titulo' => 'MacroFamilias',
      'descripcion' => 'Agrupan las familias. Vaya primero en el archivo.',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => false,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '01', 'ayuda' => 'Codigo de la macrofamilia (numero).',
        ],
        [
          'clave' => 'descripcion', 'cabecera' => 'Descripcion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 40, 'ejemplo' => 'Plantas', 'ayuda' => 'Nombre de la macrofamilia.',
        ],
      ],
    ],

    'familias' => [
      'hoja' => 'Familias',
      'titulo' => 'Familias',
      'descripcion' => 'Agrupan los articulos. La macrofamilia debe existir ya o venir en la hoja MacroFamilias.',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => false,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '001', 'ayuda' => 'Codigo de la familia (hasta 6 caracteres).',
        ],
        [
          'clave' => 'descripcion', 'cabecera' => 'Descripcion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 40, 'ejemplo' => 'Plantas de interior', 'ayuda' => 'Nombre de la familia.',
        ],
        [
          'clave' => 'macroFamiliaCodigo', 'cabecera' => 'Macrofamilia', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '01', 'ayuda' => 'Codigo de una macrofamilia (existente o de la hoja MacroFamilias).',
          'ref' => 'macrofamilias',
        ],
      ],
    ],

    'subfamilias' => [
      'hoja' => 'SubFamilias',
      'titulo' => 'SubFamilias',
      'descripcion' => 'Subdividen una familia. La familia debe existir ya o venir en la hoja Familias.',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => false,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '001', 'ayuda' => 'Numero de la subfamilia (no puede repetirse).',
        ],
        [
          'clave' => 'descripcion', 'cabecera' => 'Descripcion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 40, 'ejemplo' => 'Cactus', 'ayuda' => 'Nombre de la subfamilia.',
        ],
        [
          'clave' => 'familiaCodigo', 'cabecera' => 'Familia', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '001', 'ayuda' => 'Codigo de la familia a la que pertenece (existente o de la hoja Familias).',
          'ref' => 'familias',
        ],
      ],
    ],

    'proveedores' => [
      'hoja' => 'Proveedores',
      'titulo' => 'Proveedores',
      'descripcion' => 'Si deja el codigo vacio se numera solo (si la tienda tiene "Generar proveedor").',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => true,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '25',
          'ayuda' => 'Hasta 6 caracteres. Vacio = numeracion automatica (solo si la tienda la tiene activa).',
        ],
        [
          'clave' => 'nombre', 'cabecera' => 'Razon social', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Viveros Garcia S.L.', 'ayuda' => 'Nombre o razon social.',
        ],
        [
          'clave' => 'formaPago', 'cabecera' => 'Forma de pago', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 2, 'ejemplo' => '01', 'ayuda' => 'Codigo de una forma de pago que ya exista.',
          'ref' => 'formas-pago',
        ],
        [
          'clave' => 'nif', 'cabecera' => 'NIF', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 16, 'ejemplo' => 'B12345678', 'ayuda' => 'NIF o CIF.',
        ],
        [
          'clave' => 'personaContacto', 'cabecera' => 'Persona de contacto', 'obligatoria' => false,
          'tipo' => 'texto', 'max' => 50, 'ejemplo' => 'Ana Lopez', 'ayuda' => '',
        ],
        [
          'clave' => 'direccion', 'cabecera' => 'Direccion', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Camino Real 12', 'ayuda' => '',
        ],
        [
          'clave' => 'poblacion', 'cabecera' => 'Poblacion', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Girona', 'ayuda' => '',
        ],
        [
          'clave' => 'codigoPostal', 'cabecera' => 'Codigo postal', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 8, 'ejemplo' => '17001', 'ayuda' => '',
        ],
        [
          'clave' => 'provincia', 'cabecera' => 'Provincia', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Girona', 'ayuda' => '',
        ],
        [
          'clave' => 'pais', 'cabecera' => 'Pais', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 30, 'ejemplo' => 'España', 'ayuda' => '',
        ],
        [
          'clave' => 'telefono1', 'cabecera' => 'Telefono', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 15, 'ejemplo' => '972000000', 'ayuda' => '',
        ],
        [
          'clave' => 'telefono2', 'cabecera' => 'Telefono 2', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 15, 'ejemplo' => '600000000', 'ayuda' => '',
        ],
        [
          'clave' => 'email', 'cabecera' => 'Email', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'compras@viveros.com', 'ayuda' => '',
        ],
        [
          'clave' => 'iban', 'cabecera' => 'IBAN', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 34, 'ejemplo' => 'ES9121000418450200051332', 'ayuda' => 'Se valida el IBAN si se rellena.',
        ],
      ],
    ],

    'articulos' => [
      'hoja' => 'Articulos',
      'titulo' => 'Articulos',
      'descripcion' => 'La familia, subfamilia, agrupacion, proveedor e impuesto deben existir (o venir en este mismo archivo).',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => true,
      'unicos' => [],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 18, 'ejemplo' => '8410000000017',
          'ayuda' => 'Hasta 18 caracteres. Vacio = numeracion automatica (solo si la tienda la tiene activa).',
        ],
        [
          'clave' => 'descripcion', 'cabecera' => 'Descripcion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Ficus benjamina 20 cm', 'ayuda' => 'Nombre del articulo.',
        ],
        [
          'clave' => 'familia', 'cabecera' => 'Familia', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '001', 'ayuda' => 'Codigo de familia (existente o de la hoja Familias).',
          'ref' => 'familias',
        ],
        [
          'clave' => 'subfamilia', 'cabecera' => 'Subfamilia', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '001', 'ayuda' => 'Codigo de una subfamilia (existente o de la hoja SubFamilias).',
          'ref' => 'subfamilias',
        ],
        [
          'clave' => 'agrupacion', 'cabecera' => 'Agrupacion', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '1', 'ayuda' => 'Codigo de una agrupacion que ya exista.',
          'ref' => 'agrupaciones',
        ],
        [
          'clave' => 'proveedorHabitual', 'cabecera' => 'Proveedor', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 6, 'ejemplo' => '25', 'ayuda' => 'Codigo de proveedor (existente o de la hoja Proveedores).',
          'ref' => 'proveedores',
        ],
        [
          'clave' => 'impuestoCodigo', 'cabecera' => 'Impuesto', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 2, 'ejemplo' => '01', 'ayuda' => 'Codigo de un impuesto que ya exista.',
          'ref' => 'impuestos',
        ],
        [
          'clave' => 'precioVen1', 'cabecera' => 'Precio venta', 'obligatoria' => false, 'tipo' => 'decimal',
          'max' => null, 'ejemplo' => '12,50', 'ayuda' => 'Precio de venta (tarifa 1).',
        ],
        [
          'clave' => 'precioUltimo', 'cabecera' => 'Precio coste', 'obligatoria' => false, 'tipo' => 'decimal',
          'max' => null, 'ejemplo' => '6,00', 'ayuda' => 'Precio de coste.',
        ],
        [
          'clave' => 'alternativo', 'cabecera' => 'Codigo alternativo', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 18, 'ejemplo' => 'FIC-20', 'ayuda' => 'Referencia alternativa.',
        ],
      ],
    ],

    'clientes' => [
      'hoja' => 'Clientes',
      'titulo' => 'Clientes',
      'descripcion' => 'Si deja el codigo vacio se numera solo (si la tienda tiene "Generar clientes").',
      'claveCodigo' => 'codigo',
      'codigoAutomatico' => true,
      'unicos' => ['nif'],
      'columnas' => [
        [
          'clave' => 'codigo', 'cabecera' => 'Codigo', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 9, 'ejemplo' => '001000123',
          'ayuda' => 'Hasta 9 caracteres. Vacio = numeracion automatica (solo si la tienda la tiene activa).',
        ],
        [
          'clave' => 'nombre', 'cabecera' => 'Razon social', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Maria Perez Soler', 'ayuda' => 'Nombre o razon social.',
        ],
        [
          'clave' => 'nif', 'cabecera' => 'NIF', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 16, 'ejemplo' => '12345678Z', 'ayuda' => 'NIF, NIE o CIF. No puede repetirse entre clientes activos.',
        ],
        [
          'clave' => 'formaPago', 'cabecera' => 'Forma de pago', 'obligatoria' => true, 'tipo' => 'texto',
          'max' => 2, 'ejemplo' => '01', 'ayuda' => 'Codigo de una forma de pago que ya exista.',
          'ref' => 'formas-pago',
        ],
        [
          'clave' => 'personaContacto', 'cabecera' => 'Persona de contacto', 'obligatoria' => false,
          'tipo' => 'texto', 'max' => 50, 'ejemplo' => 'Maria', 'ayuda' => '',
        ],
        [
          'clave' => 'direccion', 'cabecera' => 'Direccion', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Carrer Major 5', 'ayuda' => '',
        ],
        [
          'clave' => 'poblacion', 'cabecera' => 'Poblacion', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Girona', 'ayuda' => '',
        ],
        [
          'clave' => 'codigoPostal', 'cabecera' => 'Codigo postal', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 8, 'ejemplo' => '17001', 'ayuda' => '',
        ],
        [
          'clave' => 'provincia', 'cabecera' => 'Provincia', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'Girona', 'ayuda' => '',
        ],
        [
          'clave' => 'pais', 'cabecera' => 'Pais', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 50, 'ejemplo' => 'España', 'ayuda' => 'Vacio = España.',
        ],
        [
          'clave' => 'telefono1', 'cabecera' => 'Telefono', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 15, 'ejemplo' => '600000000', 'ayuda' => '',
        ],
        [
          'clave' => 'telefono2', 'cabecera' => 'Telefono 2', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 15, 'ejemplo' => '972000000', 'ayuda' => '',
        ],
        [
          'clave' => 'email', 'cabecera' => 'Email', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 100, 'ejemplo' => 'maria@correo.com', 'ayuda' => '',
        ],
        [
          'clave' => 'tarjetaFidelizacion', 'cabecera' => 'Tarjeta fidelizacion', 'obligatoria' => false,
          'tipo' => 'texto', 'max' => 20, 'ejemplo' => 'XPR71015',
          'ayuda' => 'Numero de tarjeta de fidelizacion. Vacio = el cliente no tiene fidelizacion.',
        ],
        [
          'clave' => 'fechaAltaFidelizacion', 'cabecera' => 'Fecha alta fidelizacion', 'obligatoria' => false,
          'tipo' => 'texto', 'max' => 10, 'ejemplo' => '01/01/1995',
          'ayuda' => 'Fecha de alta de la tarjeta (dd/mm/aaaa). Si tiene tarjeta y no hay fecha, se usa 01/01/1995.',
        ],
        [
          'clave' => 'observaciones', 'cabecera' => 'Observaciones', 'obligatoria' => false, 'tipo' => 'texto',
          'max' => 500, 'ejemplo' => '', 'ayuda' => 'Notas libres.',
        ],
      ],
    ],
  ],

  'instrucciones' => [
    'Rellene una hoja por tipo de dato. Las columnas marcadas con * son obligatorias.',
    'No cambie los nombres de las hojas ni de las cabeceras. Puede dejar hojas vacias.',
    'Orden de carga: Impuestos, Formas de pago, MacroFamilias, Familias, SubFamilias, Proveedores, Articulos y Clientes. Cada hoja puede usar codigos de las anteriores.',
    'Las columnas Si/No admiten Si, No, 1, 0 o vacio (vacio = No).',
    'No se modifica nada que ya exista: si un codigo ya esta en la base de datos, esa fila se omite.',
    'Antes de importar use "Simular": revisa todo sin guardar y avisa de los errores por fila.',
    'Los codigos van como texto (por ejemplo 001). Los precios con coma o punto decimal.',
    'Si deja el codigo vacio (clientes, proveedores, articulos) se numera solo, pero solo si la tienda tiene la numeracion automatica activada.',
  ],
];
