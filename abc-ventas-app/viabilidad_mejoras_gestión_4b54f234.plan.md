---
name: Viabilidad mejoras gestión
overview: Aparcado a la espera de hablar con el cliente. El análisis queda guardado y no se desarrolla nada hasta retomarlo.
todos:
  - id: comentar-alcance
    content: Cerrar con el cliente las frases dudosas (transporte, nombre en blanco, exportar, transformar, imagen, geolocalización) antes de valorar
    status: cancelled
  - id: fase-documentos
    content: "Mejoras de papel y correo: pagos a cuenta visibles, tarifa jardinero solo al imprimir, pasaporte y texto en factura, fecha del correo, referencias, varios destinatarios y correos separados"
    status: cancelled
  - id: fase-pedidos
    content: "Pedidos y listados: Excel con anotación por línea, búsqueda por referencia y código postal, pendientes de servir como las facturas pendientes"
    status: cancelled
  - id: informe-apps
    content: "Informe aparte, más adelante: app de pedidos y preparación, cambio de código, AppSheet, PDA ISO 68/69"
    status: cancelled
isProject: false
---

# Mejoras de Descartes según el cliente

Aparcado el 30 de octubre de 2026, a la espera de hablar con el cliente. No se desarrolla ni se valora hasta retomarlo. El texto de abajo se conserva para esa conversación.

Fuente: notas del cliente, en catalán. El prompt de la IA no se usa como lista de trabajo. Lo que no se entiende no se diseña.

## En qué no coincide el prompt de la IA

- Partió en dos trabajos la frase del transporte. El cliente escribió una sola: emitir un documento de control de transporte del albarán, un albarán por transportista. No pidió partir el albarán de venta, el stock y la factura.
- «Exportació SI» es un sí, no un módulo. Lo concreto está en la línea siguiente: pasar un pedido a Excel y anotar por línea.
- Convirtió «APP comanda client» en una aplicación con cantidades pedidas, preparadas, pendientes y diferencias. El cliente solo dijo: app de pedido del cliente, y en la preparación confirmar cada línea con código de barras.
- Unió «transformar el producto porque es más grande» con «cambiar el código al vender». Son frases distintas. La segunda empieza por APP. La primera no se entiende y no entra en el desarrollo.
- Añadió AppSheet, PDA ISO 68/69 e imagen como integraciones a analizar. En las notas no hay definición. Quedan fuera.
- Dio por hecha una geolocalización de plantas por lote con mapa. El cliente lo apuntó, pero no dijo cómo se captura ni para qué se usa.
- Las estadísticas las amplió con producto, estado y «otros criterios». El cliente pidió tres cosas: ventas por fechas y clientes, pedidos pendientes de servir (igual que las facturas) y por cliente.

## Ya está en Descartes (no presupuestar como función nueva)

- El impreso estándar ya se titula pedido, albarán o factura. Solo habría que tocarlo si en el papel real del cliente no se distingue.
- El cliente ya tiene dirección fiscal, dirección de entrega habitual y direcciones guardadas. El albarán ya guarda su propia dirección de envío. Confirmar que eso les basta. El único fallo visto: al pasar un pedido a venta, la dirección puede copiarse de la ficha fiscal en lugar de la del pedido.
- Ventas por fechas y por cliente ya están en el listado ABC, con Excel.
- El pasaporte fitosanitario ya sale en el albarán. Falta en la factura.
- La factura ya puede llevar textos fijos de tienda o de puesto. Falta un texto al imprimir o al enviar, y el nombre de la aseguradora.
- El cliente ya tiene un correo de facturación distinto del correo general. Al enviar un albarán, ese correo no se usa: hay que escribir el destinatario a mano.
- El albarán ya tiene un importe a cuenta y, al facturar, pasa a la factura. No hay una lista de cada pago, con fecha e importe.
- País y código postal ya están en el cliente. No se rellenan solos a partir del código postal, y el país no elige el idioma del documento.
- Referencia y «su pedido» ya se guardan. En el impreso del albarán, «Su pedido» enseña el número interno, no la referencia del cliente.

## Mejoras de Descartes

Papel y correo

- Ver todos los pagos a cuenta de un albarán (fecha e importe), no solo el total. Esfuerzo medio.
- Documento de control de transporte. No se interpreta todavía: puede ser un papel distinto, uno por transportista, o partir el albarán de venta. Se pregunta al cliente antes de valorar.
- Imprimir el mismo albarán a precio de cliente y otra vez a precio de jardinero, sin cambiar el importe guardado. Esfuerzo medio. Hace falta saber qué tarifa de las existentes es la de jardinero.
- Al imprimir o enviar la factura, poder añadir un texto. Si el cliente tiene aseguradora (Cultius, Planterium), que salga el nombre. Esfuerzo pequeño si basta el campo de intereses comerciales.
- Pasaporte fitosanitario también en la factura. Esfuerzo pequeño.
- En el correo de la factura, la fecha de la factura, no la del albarán. Esfuerzo pequeño.
- Si la línea de nombre va en blanco, no imprimirla. Confirmar si es la segunda razón social del cliente (existe y el bloque del impreso no la lleva) o el nombre del producto. Esfuerzo pequeño.
- Referencias del cliente en pedido, albarán y factura, y que salgan en el papel. Esfuerzo pequeño.
- Varios destinatarios en un envío, sin un tope puesto por Descartes. El límite será el del correo. Esfuerzo pequeño.
- En la ficha del cliente, correo de facturas y correo de albaranes por separado, y usarlos al enviar. Esfuerzo pequeño.

Pedidos, consultas y comisiones

- Pasar un pedido a Excel y poder anotar cada línea. Esfuerzo medio: el Excel es pequeño; la anotación es un campo nuevo que debería pasar al albarán.
- Buscar un pedido por referencia, observaciones o la casilla de referencia del cliente. Esfuerzo pequeño: los datos ya están y no son filtros.
- Consultar pedidos y clientes por código postal, y sacar listado. Esfuerzo pequeño o medio, reutilizando el Excel que ya tienen otros listados.
- Pedidos pendientes de servir, en general y por cliente, con la misma idea que las facturas pendientes. Las ventas por fechas y cliente no se rehacen: ya están en el ABC. Esfuerzo medio.
- Origen del cliente: cliente final, jardinero y comisionista. Comisión del ejemplo: 1 % de (10.000 − 3.000) = 70 €. El ABC ya calcula otra comisión (porcentaje del vendedor sobre el margen). No sirve para este caso. Esfuerzo grande. No calcular dos veces la misma operación.

País e idioma, aparte de la traducción automática

- Codificar el país o reconocerlo por el código postal europeo, cuando se pueda. El país propone el idioma y se puede cambiar a mano. Esfuerzo medio. No cubrirá todos los códigos postales.

Traducción de la factura

- Francés e inglés con IA, al imprimir o enviar. Los importes, referencias y números no los escribe la IA: solo los rótulos, y alguien lo revisa antes de mandarlo. Esfuerzo grande. Es la única mejora grande que se queda en Descartes; el resto de lo grande son apps.

Preguntas abiertas, dentro de este informe

No se valoran hasta que el cliente las explique. Siguen en el documento de Descartes, no en el de apps.

- Transporte: papel de control, uno por transportista, o partir la venta.
- «Transformar el producto porque es más grande.»
- Albarán de coste en un lote, y geolocalizar las plantas de ese lote al introducir el albarán. Hay compras y la venta guarda lote; no hay coordenadas en el lote.
- «Imagen», aparte de las fotos al enviar el pedido.

Fuera de esta fase, a propósito

- Título pedido / albarán / factura, si el papel actual ya lo enseña.
- Dirección fiscal, de entrega y de albarán, si comprueban que ya pueden guardar una dirección en el albarán.
- Ventas por fechas y por cliente del ABC.

## Informe aparte, más adelante (apps externas)

No forma parte de la valoración de Descartes.

- App de pedidos del cliente. Preparación: confirmar cada línea leyendo el código de barras.
- App al vender: cambiar el código del producto y venderlo como otro, con entrada y salida de almacén.
- AppSheet. No está en Descartes. Preguntar qué hojas usan y si la app de pedidos las sustituye.
- PDA para la app ISO 68/69. No está en el código. Sin marca, sistema y un equipo de prueba no hay compatibilidad que afirmar.

## Preguntas para comentar con el cliente

- El documento de transporte, ¿es un papel distinto, uno por transportista, o hay que partir el albarán de venta? Se pregunta antes de valorar.
- El precio jardinero, ¿cuál de las tarifas actuales es, y el importe del albarán se queda en el precio de cliente?
- La línea de nombre en blanco, ¿es la segunda razón social o el nombre del artículo?
- «Exportació SI», ¿se refiere solo al Excel del pedido?
- Cultius y Planterium, ¿son el campo de intereses comerciales del cliente?
- El jardinero de la comisión, ¿es otro cliente, un origen, o la tarifa?
- Qué es el lote, qué lleva el albarán de coste y para qué quieren el punto en el mapa.
- Qué es «transformar el producto porque es más grande».
- Qué es la imagen que apuntaron, aparte de las fotos al enviar el pedido.
