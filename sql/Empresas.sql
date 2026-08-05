USE [larasa]
GO

/****** Object:  Table [dbo].[Empresas]    Script Date: 10/07/26 10:59:32 ******/
SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE TABLE [dbo].[Empresas](
	[Codigo] [nvarchar](3) NOT NULL,
	[Nombre] [nvarchar](50) NULL,
	[NIF] [nvarchar](16) NULL,
	[Direccion] [nvarchar](50) NULL,
	[Poblacion] [nvarchar](50) NULL,
	[CodigoPostal] [nvarchar](8) NULL,
	[Provincia] [nvarchar](50) NULL,
	[Pais] [nvarchar](30) NULL,
	[Telefono1] [nvarchar](10) NULL,
	[Telefono2] [nvarchar](10) NULL,
	[Fax] [nvarchar](10) NULL,
	[Modem] [nvarchar](10) NULL,
	[Dto] [real] NULL,
	[SW_IVA] [bit] NOT NULL,
	[ImpDatos] [bit] NOT NULL,
	[Datos] [smallint] NULL,
	[PorGasGen] [real] NULL,
	[PorGasAlm] [real] NULL,
	[Prevision] [float] NULL,
	[Critico] [float] NULL,
	[UltPedidoCom] [int] NULL,
	[UltAlbaranCom] [int] NULL,
	[UltAlbaranVen] [int] NULL,
	[UltFactura] [int] NULL,
	[UltTicket] [int] NULL,
	[Tarifa] [smallint] NULL,
	[UltAlbaranDevCom] [int] NULL,
	[UltAlbaranTra] [int] NULL,
	[Recargo] [bit] NOT NULL,
	[Divisa] [nvarchar](2) NULL,
	[DivisaAlt] [nvarchar](2) NULL,
	[Prefijo] [real] NULL,
	[CodigoExterno] [nvarchar](10) NULL,
	[UltEnvio] [int] NULL,
	[EtiB] [float] NULL,
	[EtiF] [float] NULL,
	[InfB] [float] NULL,
	[InfF] [float] NULL,
	[CamB] [float] NULL,
	[CamF] [float] NULL,
	[PanB] [float] NULL,
	[PanF] [float] NULL,
	[BotB] [float] NULL,
	[BotF] [float] NULL,
	[UltPedidoCli] [int] NULL,
	[Central] [bit] NOT NULL,
	[UltimaComunicacion] [datetime] NULL,
	[FacturaLaCentral] [bit] NOT NULL,
	[EMail] [nvarchar](50) NULL,
	[CheckProveedor] [bit] NOT NULL,
	[ImpAlternativo] [bit] NOT NULL,
	[ImpTicketInc] [bit] NOT NULL,
	[AlmacenesInternos] [bit] NOT NULL,
	[Vales_Em] [float] NULL,
	[Vales_Re] [float] NULL,
	[DecimalesPrecio] [smallint] NULL,
	[NombreFiscal] [varchar](200) NULL,
	[Aecoc] [float] NULL,
	[GenBarras] [bit] NOT NULL,
	[GenDesdeCodigo] [bit] NOT NULL,
	[UltEan] [int] NULL,
	[GenClientes] [bit] NOT NULL,
	[UltCliente] [int] NULL,
	[ComunicaCentral] [bit] NOT NULL,
	[UnidadComunicacion] [nvarchar](2) NULL,
	[UltVale] [int] NULL,
	[ConexionOnline] [bit] NOT NULL,
	[GenerarFicheroDesconexion] [bit] NOT NULL,
	[DesglosarBasesTicketIvaInc] [bit] NOT NULL,
	[AgruparArticulosCocina] [bit] NOT NULL,
	[AgruparArticulosTiquet] [bit] NOT NULL,
	[CopiasAlbaranReparto] [smallint] NULL,
	[ImprimirCodigoArticulo] [bit] NOT NULL,
	[UltEnvioTransporte] [int] NULL,
	[AvisoStockCero] [bit] NOT NULL,
	[CopiasAlbaran] [smallint] NULL,
	[CopiasFactura] [smallint] NULL,
	[ImpEtiquetasSinEans] [smallint] NULL,
	[ImpEtiquetasSoloEansPropios] [smallint] NULL,
	[EtiquetasIvaIncluido] [bit] NOT NULL,
	[ImprimirEscandalloCocina] [bit] NOT NULL,
	[ArticuloCubiertos] [nvarchar](18) NULL,
	[ImprimirMenus] [bit] NOT NULL,
	[ProtocoloHotel] [smallint] NULL,
	[UltimaComunicacionHotel] [datetime] NULL,
	[ArqueoCentro] [bit] NOT NULL,
	[TarjetaObligatoriaSS] [bit] NOT NULL,
	[TarjetaObligatoriaHD] [bit] NOT NULL,
	[TarjetaObligatoriaMP] [bit] NOT NULL,
	[TarjetaObligatoriaPC] [bit] NOT NULL,
	[TarjetaObligatoriaTI] [bit] NOT NULL,
	[DesayunoHotelAdultos] [nvarchar](18) NULL,
	[DesayunoHotelNiños] [nvarchar](18) NULL,
	[AlmuerzoHotelAdultos] [nvarchar](18) NULL,
	[AlmuerzoHotelNiños] [nvarchar](18) NULL,
	[CenaHotelAdultos] [nvarchar](18) NULL,
	[CenaHotelNiños] [nvarchar](18) NULL,
	[HoraSalidaHotel] [nvarchar](5) NULL,
	[UltFicheroRecepcion] [int] NULL,
	[UltFicheroRecepcionC] [int] NULL,
	[RecalculoTarifas] [nvarchar](2) NULL,
	[ComprobarSaldoHabitacion] [bit] NOT NULL,
	[upsize_ts] [timestamp] NULL,
	[RegimenCanario] [bit] NULL,
	[CodIvaNormal] [nchar](3) NULL,
	[CodIvaReducido] [nchar](3) NULL,
	[CodIvaIncrementado] [nchar](3) NULL,
	[SerieFacturas] [nchar](3) NULL,
	[ObligarDineroEntregado] [bit] NULL,
	[GenArticulos] [bit] NULL,
	[CambiaProveedor] [bit] NULL,
	[MaximizarListado] [bit] NULL,
	[LiteralFactura] [nchar](200) NULL,
	[LiteralFacturaContado] [nchar](200) NULL,
	[FacturasRectificativas] [bit] NULL,
	[UltAbono] [int] NULL,
	[SerieAbonos] [nchar](3) NULL,
	[CodIvaSuperReducido] [nchar](3) NULL,
	[ImprimirTicketHotel0] [bit] NULL,
	[LiteralInvitacion] [smallint] NULL,
	[MinimoFidelizacion] [float] NULL,
	[ImpSaldoTicket] [bit] NULL,
	[CrearReciboCredito] [bit] NULL,
	[SumarDescuento] [bit] NULL,
	[ImporteObligatorioFactura] [float] NULL,
	[MinimoCambioVales] [float] NULL,
	[LiteralPresupuesto] [nchar](200) NULL,
	[LiteralVale] [nchar](50) NULL,
	[DtoSiPrecioCero] [bit] NULL,
	[DtoSiCambioPrecio] [bit] NULL,
	[DtoPrioridadOperador] [bit] NULL,
	[TeclaDtoIgualInvitacion] [bit] NULL,
	[SolicitarPerfil] [bit] NULL,
	[PeditCbtosCtaSeparadas] [bit] NULL,
	[PedirCbtosCtaSeparadas] [bit] NULL,
	[IntPreciosIvaIncludo] [bit] NULL,
	[ControlVales] [bit] NULL,
	[LiteralTicket] [int] NULL,
	[CopiasSimulacion] [smallint] NULL,
	[UltProyecto] [int] NULL,
	[UltTrabajo] [int] NULL,
	[UltOperacionTef] [int] NULL,
	[UltTarjeta] [int] NULL,
	[PasaporteFitosanitario] [nvarchar](20) NULL,
	[PjeRetIrpf] [real] NULL,
	[CtbRetIrpf] [float] NULL,
	[UltListaNegra] [int] NULL,
	[Almacen] [real] NULL,
	[UltProveedor] [int] NULL,
	[GenProveedores] [bit] NULL,
	[UltPreFactura] [int] NULL,
	[Literal4] [nvarchar](56) NULL,
	[Literal5] [nvarchar](56) NULL,
	[DesglosarEscandallo] [bit] NULL,
	[CopiasFacturaReparto] [real] NULL,
	[UltSeccionAnalitica] [nvarchar](10) NULL,
	[UltTeatro] [nvarchar](10) NULL,
	[UltimaComunicacionCTB] [datetime] NULL,
	[Tarifa1Escalado] [bit] NULL,
	[Tarifa2Escalado] [bit] NULL,
	[Tarifa3Escalado] [bit] NULL,
	[Tarifa4Escalado] [bit] NULL,
	[Tarifa5Escalado] [bit] NULL,
	[Tarifa6Escalado] [bit] NULL,
	[Tarifa7Escalado] [bit] NULL,
	[Tarifa8Escalado] [bit] NULL,
	[Tarifa9Escalado] [bit] NULL,
	[Atri0] [nchar](15) NULL,
	[Atri1] [nchar](15) NULL,
	[Atri2] [nchar](15) NULL,
	[Atri3] [nchar](15) NULL,
	[Atri4] [nchar](15) NULL,
	[Atri5] [nchar](15) NULL,
	[Atri6] [nchar](15) NULL,
	[Atri7] [nchar](15) NULL,
	[Atri8] [nchar](15) NULL,
	[Atri9] [nchar](15) NULL,
	[ColAtri0] [int] NULL,
	[ColAtri1] [int] NULL,
	[ColAtri2] [int] NULL,
	[ColAtri3] [int] NULL,
	[ColAtri4] [int] NULL,
	[ColAtri5] [int] NULL,
	[ColAtri6] [int] NULL,
	[ColAtri7] [int] NULL,
	[ColAtri8] [int] NULL,
	[ColAtri9] [int] NULL,
	[UltFacturaDiferida] [int] NULL,
	[UltAbonoDiferido] [int] NULL,
	[ArticuloMantenimiento] [nvarchar](18) NULL,
	[ArticuloTransporte] [nvarchar](18) NULL,
	[PjeTransporte] [float] NULL,
	[PjeIVATransporte] [float] NULL,
	[UltAsiento] [int] NULL,
	[UltOrdenFabricacion] [int] NULL,
	[LitPiePedidoCompra] [ntext] NULL,
	[PathExcelEstadisticas] [nvarchar](100) NULL,
	[CentroCoste] [nvarchar](10) NULL,
	[BloqueoFidelizacion] [bit] NULL,
	[SorteosActivos] [bit] NULL,
	[AdressId] [int] NULL,
	[FechaUltimaVerificacionPedidosWeb] [datetime] NULL,
	[UltTransacCajon] [int] NULL,
	[UltimaComunicacionAliat] [datetime] NULL,
	[UltimaComunicacionWeb] [datetime] NULL,
	[UltimaRecepcionPedidos] [datetime] NULL,
	[UltimoEnvioStock] [datetime] NULL,
	[UltSerieTBAI] [nvarchar](4) NULL,
	[UltFacturaTBAI] [int] NULL,
	[Literal6] [nvarchar](56) NULL,
	[Literal7] [nvarchar](56) NULL,
	[Literal8] [nvarchar](56) NULL,
	[Literal9] [nvarchar](56) NULL,
	[Literal10] [nvarchar](56) NULL,
	[Literal11] [nvarchar](56) NULL,
	[Literal12] [nvarchar](56) NULL,
	[Literal13] [nvarchar](56) NULL,
	[Literal14] [nvarchar](56) NULL,
	[Literal15] [nvarchar](56) NULL,
	[Literal16] [nvarchar](56) NULL,
	[Literal17] [nvarchar](56) NULL,
	[Literal18] [nvarchar](56) NULL,
	[Literal19] [nvarchar](56) NULL,
	[Literal20] [nvarchar](56) NULL,
	[Literal21] [nvarchar](56) NULL,
	[Literal22] [nvarchar](56) NULL,
	[Literal23] [nvarchar](56) NULL,
	[Literal24] [nvarchar](56) NULL,
	[Literal25] [nvarchar](56) NULL,
	[Literal26] [nvarchar](56) NULL,
	[Literal27] [nvarchar](56) NULL,
	[Literal28] [nvarchar](56) NULL,
	[Literal29] [nvarchar](56) NULL,
	[Literal30] [nvarchar](56) NULL,
	[CentrarlLiteralesAdicionales] [bit] NULL,
	[TicketSI_Certificado] [nvarchar](600) NULL,
	[TicketSI_CertificadoCP] [nvarchar](100) NULL,
	[CodIvaEsp5] [nchar](3) NULL,
	[CodIvaEsp6] [nchar](3) NULL,
	[TicketSI_Territorio] [nvarchar](9) NULL,
	[AppWeb] [bit] NULL,
	[UltAbonoTicket] [int] NULL,
	[Baja] [bit] NOT NULL,
 CONSTRAINT [aaaaaEmpresas_PK] PRIMARY KEY NONCLUSTERED 
(
	[Codigo] ASC
)WITH (PAD_INDEX = OFF, STATISTICS_NORECOMPUTE = OFF, IGNORE_DUP_KEY = OFF, ALLOW_ROW_LOCKS = ON, ALLOW_PAGE_LOCKS = ON, OPTIMIZE_FOR_SEQUENTIAL_KEY = OFF) ON [PRIMARY]
) ON [PRIMARY] TEXTIMAGE_ON [PRIMARY]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__TemporaryUp__Dto__7A3223E8]  DEFAULT (0) FOR [Dto]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__SW_IV__7B264821]  DEFAULT (0) FOR [SW_IVA]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__ImpDa__7C1A6C5A]  DEFAULT (0) FOR [ImpDatos]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Datos__7D0E9093]  DEFAULT (0) FOR [Datos]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__PorGa__7E02B4CC]  DEFAULT (0) FOR [PorGasGen]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__PorGa__7EF6D905]  DEFAULT (0) FOR [PorGasAlm]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Previ__7FEAFD3E]  DEFAULT (0) FOR [Prevision]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Criti__00DF2177]  DEFAULT (0) FOR [Critico]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__UltPe__01D345B0]  DEFAULT (0) FOR [UltPedidoCom]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__UltAl__02C769E9]  DEFAULT (0) FOR [UltAlbaranCom]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__UltAl__03BB8E22]  DEFAULT (0) FOR [UltAlbaranVen]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__UltFa__04AFB25B]  DEFAULT (0) FOR [UltFactura]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__UltTi__05A3D694]  DEFAULT (0) FOR [UltTicket]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Tarif__0697FACD]  DEFAULT (0) FOR [Tarifa]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__UltAl__078C1F06]  DEFAULT (0) FOR [UltAlbaranDevCom]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Recar__0880433F]  DEFAULT (0) FOR [Recargo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Centr__09746778]  DEFAULT (0) FOR [Central]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Factu__0A688BB1]  DEFAULT (0) FOR [FacturaLaCentral]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Check__0B5CAFEA]  DEFAULT (0) FOR [CheckProveedor]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__ImpAl__0C50D423]  DEFAULT (0) FOR [ImpAlternativo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__ImpTi__0D44F85C]  DEFAULT (0) FOR [ImpTicketInc]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Almac__0E391C95]  DEFAULT (0) FOR [AlmacenesInternos]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__GenBa__0F2D40CE]  DEFAULT (0) FOR [GenBarras]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__GenDe__10216507]  DEFAULT (0) FOR [GenDesdeCodigo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__GenCl__11158940]  DEFAULT (0) FOR [GenClientes]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Comun__1209AD79]  DEFAULT (0) FOR [ComunicaCentral]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Conex__12FDD1B2]  DEFAULT (0) FOR [ConexionOnline]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Gener__13F1F5EB]  DEFAULT (0) FOR [GenerarFicheroDesconexion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Desgl__14E61A24]  DEFAULT (0) FOR [DesglosarBasesTicketIvaInc]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Agrup__15DA3E5D]  DEFAULT (0) FOR [AgruparArticulosCocina]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Agrup__16CE6296]  DEFAULT (0) FOR [AgruparArticulosTiquet]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Impri__17C286CF]  DEFAULT (0) FOR [ImprimirCodigoArticulo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Aviso__18B6AB08]  DEFAULT (0) FOR [AvisoStockCero]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Etiqu__19AACF41]  DEFAULT (0) FOR [EtiquetasIvaIncluido]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Impri__1A9EF37A]  DEFAULT (0) FOR [ImprimirEscandalloCocina]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Impri__1B9317B3]  DEFAULT (0) FOR [ImprimirMenus]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Proto__1C873BEC]  DEFAULT (0) FOR [ProtocoloHotel]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Arque__1D7B6025]  DEFAULT (0) FOR [ArqueoCentro]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Tarje__1E6F845E]  DEFAULT (0) FOR [TarjetaObligatoriaSS]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Tarje__1F63A897]  DEFAULT (0) FOR [TarjetaObligatoriaHD]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Tarje__2057CCD0]  DEFAULT (0) FOR [TarjetaObligatoriaMP]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Tarje__214BF109]  DEFAULT (0) FOR [TarjetaObligatoriaPC]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Tarje__22401542]  DEFAULT (0) FOR [TarjetaObligatoriaTI]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Compr__2334397B]  DEFAULT (0) FOR [ComprobarSaldoHabitacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__REGIMENC]  DEFAULT (0) FOR [RegimenCanario]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__OBLIGARD]  DEFAULT (0) FOR [ObligarDineroEntregado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__GENARTIC]  DEFAULT (0) FOR [GenArticulos]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__CAMBIAPR]  DEFAULT (0) FOR [CambiaProveedor]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__MAXIMIZA]  DEFAULT (0) FOR [MaximizarListado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__FACTURASREC]  DEFAULT (0) FOR [FacturasRectificativas]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__ULTABONO]  DEFAULT (0) FOR [UltAbono]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__IMPRIMIRTICKE]  DEFAULT (0) FOR [ImprimirTicketHotel0]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__LITERALINVITA]  DEFAULT (0) FOR [LiteralInvitacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__MINIMOFIDELIZ]  DEFAULT (0) FOR [MinimoFidelizacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__IMPSALDOTICKE]  DEFAULT (0) FOR [ImpSaldoTicket]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__CREARRECIBOCR]  DEFAULT (0) FOR [CrearReciboCredito]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__SUMARDESCUENT]  DEFAULT (0) FOR [SumarDescuento]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__IMPORTEOBLIGA]  DEFAULT (0) FOR [ImporteObligatorioFactura]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__MINIMOCAMBIOV]  DEFAULT (0) FOR [MinimoCambioVales]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__DTOSIPRECIOCE]  DEFAULT (0) FOR [DtoSiPrecioCero]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__DTOSICAMBIOPR]  DEFAULT (0) FOR [DtoSiCambioPrecio]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__DTOPRIORIDADO]  DEFAULT (0) FOR [DtoPrioridadOperador]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__TECLADTOIGUAL]  DEFAULT (0) FOR [TeclaDtoIgualInvitacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__SOLICITARPERF]  DEFAULT (0) FOR [SolicitarPerfil]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__PEDITCBTOSCTA]  DEFAULT (0) FOR [PeditCbtosCtaSeparadas]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__PEDIRCBTOSCTA]  DEFAULT (0) FOR [PedirCbtosCtaSeparadas]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__INTPRECIOSIVA]  DEFAULT (0) FOR [IntPreciosIvaIncludo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__CONTROLVALES]  DEFAULT (0) FOR [ControlVales]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__LITERALTICKET]  DEFAULT (0) FOR [LiteralTicket]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__COPIASSIMULAC]  DEFAULT (0) FOR [CopiasSimulacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__ULTPROYECTO]  DEFAULT (0) FOR [UltProyecto]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__ULTTRABAJO]  DEFAULT (0) FOR [UltTrabajo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__ULTOPERACIONT]  DEFAULT (0) FOR [UltOperacionTef]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre__ULTTARJETA]  DEFAULT (0) FOR [UltTarjeta]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kPJERETIRPF]  DEFAULT (0) FOR [PjeRetIrpf]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kCTBRETIRPF]  DEFAULT (0) FOR [CtbRetIrpf]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kULTLISTANEGRA]  DEFAULT (0) FOR [UltListaNegra]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kALMACEN]  DEFAULT (0) FOR [Almacen]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kULTPROVEEDOR]  DEFAULT (0) FOR [UltProveedor]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kGENPROVEEDORE]  DEFAULT (0) FOR [GenProveedores]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kULTPREFACTURA]  DEFAULT (0) FOR [UltPreFactura]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kDESGLOSARESCA]  DEFAULT (0) FOR [DesglosarEscandallo]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF__Temporary__Empre_kCOPIASFACTURA]  DEFAULT (0) FOR [CopiasFacturaReparto]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA1ESCALA]  DEFAULT ((0)) FOR [Tarifa1Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA2ESCALA]  DEFAULT ((0)) FOR [Tarifa2Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA3ESCALA]  DEFAULT ((0)) FOR [Tarifa3Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA4ESCALA]  DEFAULT ((0)) FOR [Tarifa4Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA5ESCALA]  DEFAULT ((0)) FOR [Tarifa5Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA6ESCALA]  DEFAULT ((0)) FOR [Tarifa6Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA7ESCALA]  DEFAULT ((0)) FOR [Tarifa7Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA8ESCALA]  DEFAULT ((0)) FOR [Tarifa8Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kTARIFA9ESCALA]  DEFAULT ((0)) FOR [Tarifa9Escalado]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI0]  DEFAULT ((0)) FOR [ColAtri0]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI1]  DEFAULT ((0)) FOR [ColAtri1]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI2]  DEFAULT ((0)) FOR [ColAtri2]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI3]  DEFAULT ((0)) FOR [ColAtri3]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI4]  DEFAULT ((0)) FOR [ColAtri4]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI5]  DEFAULT ((0)) FOR [ColAtri5]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI6]  DEFAULT ((0)) FOR [ColAtri6]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI7]  DEFAULT ((0)) FOR [ColAtri7]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI8]  DEFAULT ((0)) FOR [ColAtri8]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCOLATRI9]  DEFAULT ((0)) FOR [ColAtri9]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTFACTURADIF]  DEFAULT ((0)) FOR [UltFacturaDiferida]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTABONODIFER]  DEFAULT ((0)) FOR [UltAbonoDiferido]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kPJETRANSPORTE]  DEFAULT ((0)) FOR [PjeTransporte]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kPJEIVATRANSPO]  DEFAULT ((0)) FOR [PjeIVATransporte]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTASIENTO]  DEFAULT ((0)) FOR [UltAsiento]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTORDENFABRI]  DEFAULT ((0)) FOR [UltOrdenFabricacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kBLOQUEOFIDELI]  DEFAULT ((0)) FOR [BloqueoFidelizacion]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kSORTEOSACTIVO]  DEFAULT ((0)) FOR [SorteosActivos]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kADRESSID]  DEFAULT ((0)) FOR [AdressId]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTTRANSACCAJ]  DEFAULT ((0)) FOR [UltTransacCajon]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTFACTURATBAI]  DEFAULT ((0)) FOR [UltFacturaTBAI]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kCENTRARLLITERALESADICIONALES]  DEFAULT ((0)) FOR [CentrarlLiteralesAdicionales]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kAPPWEB]  DEFAULT ((0)) FOR [AppWeb]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [EF_Empresas_kULTABONOTICKET]  DEFAULT ((0)) FOR [UltAbonoTicket]
GO

ALTER TABLE [dbo].[Empresas] ADD  CONSTRAINT [DF_Empresas_Baja]  DEFAULT ((0)) FOR [Baja]
GO

ALTER TABLE [dbo].[Empresas]  WITH NOCHECK ADD  CONSTRAINT [Empresas_FK00] FOREIGN KEY([Codigo])
REFERENCES [dbo].[Parametros] ([Empresa])
GO

ALTER TABLE [dbo].[Empresas] NOCHECK CONSTRAINT [Empresas_FK00]
GO

