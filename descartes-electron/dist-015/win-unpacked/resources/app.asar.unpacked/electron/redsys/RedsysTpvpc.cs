using System;
using System.Collections.Generic;
using System.IO;
using System.Reflection;
using System.Runtime.InteropServices;
using System.Text;
using System.Windows.Forms;

/// <summary>
/// Puente Redsys: inicializa por COM (como VentaGen) y devoluciones por exportaciones
/// de dllTpvpcLatente.dll (OperComContable / ComContableTrj no están en el COM).
/// </summary>
class RedsysTpvpc
{
  const int XmlBufferSize = 2048;

  [DllImport("kernel32.dll", CharSet = CharSet.Unicode, SetLastError = true)]
  static extern bool SetDllDirectory(string lpPathName);

  [DllImport("dllTpvpcLatente.dll", CharSet = CharSet.Ansi, EntryPoint = "fnDllOperComContable")]
  static extern int OperComContableDll(
    string pedido, string rts, string importe, string factura, string tipoOper,
    StringBuilder xml, int tamMax);

  [DllImport("dllTpvpcLatente.dll", CharSet = CharSet.Ansi, EntryPoint = "fnDllComContableTrj")]
  static extern int ComContableTrjDll(
    string importe, string factura, string pedido, string rts, StringBuilder xml, int tamMax);

  [DllImport("dllTpvpcLatente.dll", CharSet = CharSet.Ansi, EntryPoint = "fnDllDevSinOrigTrj")]
  static extern int DevSinOrigTrjDll(string importe, string factura, StringBuilder xml, int tamMax);

  [DllImport("dllTpvpcLatente.dll", CharSet = CharSet.Ansi, EntryPoint = "fnDllIniTpvpcLatente")]
  static extern int IniTpvpcLatenteDll(
    string comercio, string terminal, string clave, string puerto, string version);

  [DllImport("dllTpvpcLatente.dll", CharSet = CharSet.Ansi, EntryPoint = "fnDllParaTpvpcLatente")]
  static extern int ParaTpvpcLatenteDll();

  [STAThread]
  static int Main()
  {
    var datos = LeerEntrada();
    object tpv = null;
    Type tipo = null;
    try
    {
      PrepararRutaDll();

      tipo = Type.GetTypeFromProgID("DllTpvpcLatente.TpvpImplantado");
      if (tipo == null)
      {
        Responder(false, null, "No esta registrada la libreria de Redsys (DllTpvpcLatente)");
        return 1;
      }
      tpv = Activator.CreateInstance(tipo);
      Application.DoEvents();

      var comercio = Valor(datos, "comercio");
      var terminal = Valor(datos, "terminal");
      var clave = Valor(datos, "clave");
      var puerto = Valor(datos, "puerto");
      var version = Valor(datos, "version");

      var tipoOp = Valor(datos, "tipoOperacion");
      if (string.IsNullOrWhiteSpace(tipoOp)) tipoOp = "PAGO";
      tipoOp = tipoOp.ToUpperInvariant();

      // OperPinPad solo funciona sobre el objeto COM inicializado; las devoluciones
      // van por exportaciones de la DLL, que necesitan su propia inicializacion.
      var porDll = tipoOp == "DEVOLUCION" && Valor(datos, "action") != "status";
      string viaIni;
      int ini = Inicializar(tipo, tpv, porDll, comercio, terminal, clave, puerto, version, out viaIni);
      if (ini != 0)
      {
        Responder(false, null, "No se pudo inicializar el datafono (codigo " + ini + ")");
        return 0;
      }

      var extra = new Dictionary<string, string>();
      extra["viaIni"] = viaIni;
      extra["serie"] = Texto(Propiedad(tipo, tpv, "NumSerieTerminalFisico"));
      extra["serieLogico"] = Texto(Propiedad(tipo, tpv, "NumSerieTerminalLogico"));

      if (Valor(datos, "action") == "status")
      {
        Responder(true, extra, "");
        return 0;
      }

      string xmlDirecto = null;
      if (tipoOp == "DEVOLUCION")
      {
        var importe = Valor(datos, "importe");
        var factura = Valor(datos, "factura");
        var codAut = Valor(datos, "codigoAutorizacion");
        if (string.IsNullOrWhiteSpace(factura) && !string.IsNullOrWhiteSpace(codAut))
          factura = codAut;
        if (string.IsNullOrWhiteSpace(factura)) factura = Valor(datos, "pedido");
        var pedidoOrig = Valor(datos, "pedidoOriginal");
        var rts = Valor(datos, "rtsOriginal");
        var sinOrig = Valor(datos, "devolucionSinOriginal") == "1";

        int codDll;
        string via;
        string xmlOper = null;
        var pinpad = Valor(datos, "devolucionPinpad") == "1";
        var legacyRts = Valor(datos, "modoLegacyRts") == "1"
          || (!string.IsNullOrWhiteSpace(rts) && string.IsNullOrWhiteSpace(pedidoOrig));
        if (!string.IsNullOrWhiteSpace(pedidoOrig) && pinpad)
        {
          via = "ComContableTrjDll";
          codDll = EjecutarComContableTrj(importe, factura, pedidoOrig, rts, out xmlDirecto);
          extra["codComTrj"] = codDll.ToString();
          if (!string.IsNullOrWhiteSpace(xmlDirecto)) extra["xmlComTrj"] = xmlDirecto;
        }
        else if (legacyRts && !string.IsNullOrWhiteSpace(rts))
        {
          // VentaGen LOG_TAR: OPERCOMCONTABLE)RTS,importe,factura,DEVOLUCION
          via = "OperComContableLegacyRts";
          codDll = EjecutarOperComContableLegacyRts(rts, importe, factura, out xmlOper);
          extra["codOperCom"] = codDll.ToString();
          if (!string.IsNullOrWhiteSpace(xmlOper)) extra["xmlOperCom"] = xmlOper;
          xmlDirecto = xmlOper;
        }
        else if (!string.IsNullOrWhiteSpace(pedidoOrig))
        {
          via = "OperComContableDll";
          codDll = EjecutarOperComContable(pedidoOrig, rts, importe, factura, out xmlOper);
          extra["codOperCom"] = codDll.ToString();
          if (!string.IsNullOrWhiteSpace(xmlOper)) extra["xmlOperCom"] = xmlOper;
          xmlDirecto = xmlOper;
        }
        else if (sinOrig)
        {
          via = "DevSinOrigTrjDll";
          codDll = EjecutarDevSinOrig(importe, factura, out xmlDirecto);
        }
        else
        {
          Responder(false, extra, "Indique pedido Redsys del cobro original.");
          return 0;
        }

        extra["via"] = via;
        extra["codDll"] = codDll.ToString();
        BombeoMensajes(120);
      }
      else
      {
        Invocar(tipo, tpv, "OperPinPad",
          Valor(datos, "importe"), Valor(datos, "moneda"), tipoOp, Valor(datos, "pedido"));
        BombeoMensajes(120);
      }

      if (string.IsNullOrWhiteSpace(xmlDirecto))
        xmlDirecto = Texto(Propiedad(tipo, tpv, "ResultOper"));
      extra["xml"] = xmlDirecto ?? "";
      extra["codError"] = Texto(Propiedad(tipo, tpv, "CodError"));

      string errCod;
      string errMsg;
      if (TryParseErrorXml(xmlDirecto, out errCod, out errMsg))
      {
        Responder(false, extra, (errCod + ": " + errMsg).Trim(' ', ':'));
        return 0;
      }
      if (tipoOp == "DEVOLUCION" && !DevolucionXmlAutorizada(xmlDirecto))
      {
        Responder(false, extra, "Redsys no devolvio autorizacion en la devolucion.");
        return 0;
      }
      if (string.IsNullOrWhiteSpace(xmlDirecto))
      {
        Responder(false, extra, "El datafono no devolvio respuesta (init " + viaIni + ").");
        return 0;
      }

      Responder(true, extra, "");
      return 0;
    }
    catch (Exception e)
    {
      var raiz = e;
      while (raiz.InnerException != null) raiz = raiz.InnerException;
      Responder(false, null, raiz.Message);
      return 1;
    }
    finally
    {
      if (tpv != null)
      {
        if (iniPorDll) { try { ParaTpvpcLatenteDll(); } catch { } }
        try { Invocar(tipo, tpv, "ParaTpvpcLatente"); } catch { }
        try { Marshal.FinalReleaseComObject(tpv); } catch { }
      }
    }
  }

  /// <summary>
  /// COM y DLL mantienen sesiones distintas: hay que inicializar la que use la operacion.
  /// </summary>
  static int Inicializar(
    Type tipo, object tpv, bool porDll,
    string comercio, string terminal, string clave, string puerto, string version,
    out string via)
  {
    int ini;
    if (porDll)
    {
      via = "dll";
      ini = IniTpvpcLatenteDll(comercio, terminal, clave, puerto, version);
      Application.DoEvents();
      if (ini == 0) { iniPorDll = true; return 0; }
      via = "com";
      ini = IniCom(tipo, tpv, comercio, terminal, clave, puerto, version);
      return ini;
    }

    via = "com";
    ini = IniCom(tipo, tpv, comercio, terminal, clave, puerto, version);
    if (ini == 0) return 0;
    via = "dll";
    ini = IniTpvpcLatenteDll(comercio, terminal, clave, puerto, version);
    Application.DoEvents();
    if (ini == 0) iniPorDll = true;
    return ini;
  }

  static bool iniPorDll;

  static int IniCom(
    Type tipo, object tpv,
    string comercio, string terminal, string clave, string puerto, string version)
  {
    try
    {
      var cod = Convert.ToInt32(
        Invocar(tipo, tpv, "IniTpvpcLatente", comercio, terminal, clave, puerto, version));
      Application.DoEvents();
      return cod;
    }
    catch
    {
      return -1;
    }
  }

  static bool DevolucionXmlAutorizada(string xml)
  {
    if (string.IsNullOrWhiteSpace(xml)) return false;
    string errCod;
    string errMsg;
    if (TryParseErrorXml(xml, out errCod, out errMsg)) return false;
    return xml.IndexOf("autorizad", StringComparison.OrdinalIgnoreCase) >= 0;
  }

  static bool TryParseErrorXml(string xml, out string codigo, out string mensaje)
  {
    codigo = "";
    mensaje = "";
    if (string.IsNullOrWhiteSpace(xml)) return false;
    if (xml.IndexOf("<Error>", StringComparison.OrdinalIgnoreCase) < 0) return false;
    codigo = ExtraerTag(xml, "codigo");
    mensaje = ExtraerTag(xml, "mensaje");
    if (string.IsNullOrWhiteSpace(mensaje)) mensaje = ExtraerTag(xml, "descripcion");
    return true;
  }

  static string ExtraerTag(string xml, string tag)
  {
    var open = "<" + tag + ">";
    var close = "</" + tag + ">";
    var i = xml.IndexOf(open, StringComparison.OrdinalIgnoreCase);
    if (i < 0) return "";
    i += open.Length;
    var j = xml.IndexOf(close, i, StringComparison.OrdinalIgnoreCase);
    if (j < 0) return "";
    return xml.Substring(i, j - i).Trim();
  }

  static void PrepararRutaDll()
  {
    var candidatos = new[]
    {
      Environment.GetEnvironmentVariable("TPVPCIMPLANTADO"),
      Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ProgramFilesX86), "TpvpcImplantado"),
      @"C:\Program Files (x86)\TpvpcImplantado",
      @"C:\Program Files\TpvpcImplantado",
    };
    foreach (var dir in candidatos)
    {
      if (string.IsNullOrWhiteSpace(dir)) continue;
      var dll = Path.Combine(dir, "dllTpvpcLatente.dll");
      if (!File.Exists(dll)) continue;
      SetDllDirectory(dir);
      return;
    }
  }

  static int EjecutarOperComContable(
    string pedido, string rts, string importe, string factura, out string xml)
  {
    var buf = new StringBuilder(XmlBufferSize);
    var cod = OperComContableDll(pedido, rts ?? "", importe, factura ?? "", "DEVOLUCION", buf, XmlBufferSize);
    xml = buf.ToString();
    Application.DoEvents();
    return cod;
  }

  static int EjecutarOperComContableLegacyRts(string rts, string importe, string factura, out string xml)
  {
    var buf = new StringBuilder(XmlBufferSize);
    var cod = OperComContableDll(rts, "", importe, factura ?? "", "DEVOLUCION", buf, XmlBufferSize);
    xml = buf.ToString();
    Application.DoEvents();
    return cod;
  }

  static int EjecutarComContableTrj(
    string importe, string factura, string pedido, string rts, out string xml)
  {
    var buf = new StringBuilder(XmlBufferSize);
    var cod = ComContableTrjDll(importe, factura ?? "", pedido, rts ?? "", buf, XmlBufferSize);
    xml = buf.ToString();
    Application.DoEvents();
    BombeoMensajes(60);
    return cod;
  }

  static int EjecutarDevSinOrig(string importe, string factura, out string xml)
  {
    var buf = new StringBuilder(XmlBufferSize);
    var cod = DevSinOrigTrjDll(importe, factura ?? "", buf, XmlBufferSize);
    xml = buf.ToString();
    Application.DoEvents();
    return cod;
  }

  static void BombeoMensajes(int ciclos)
  {
    for (var i = 0; i < ciclos; i++)
    {
      Application.DoEvents();
      System.Threading.Thread.Sleep(50);
    }
  }

  static Dictionary<string, string> LeerEntrada()
  {
    var datos = new Dictionary<string, string>();
    string linea;
    while ((linea = Console.In.ReadLine()) != null)
    {
      int corte = linea.IndexOf('=');
      if (corte <= 0) continue;
      datos[linea.Substring(0, corte).Trim()] = linea.Substring(corte + 1).Trim();
    }
    return datos;
  }

  static string Valor(Dictionary<string, string> datos, string clave)
  {
    string v;
    return datos.TryGetValue(clave, out v) ? v : "";
  }

  static object Invocar(Type tipo, object obj, string metodo, params object[] args)
  {
    return tipo.InvokeMember(metodo, BindingFlags.InvokeMethod, null, obj, args);
  }

  static object Propiedad(Type tipo, object obj, string nombre)
  {
    try { return tipo.InvokeMember(nombre, BindingFlags.GetProperty, null, obj, null); }
    catch { return null; }
  }

  static string Texto(object valor)
  {
    return valor == null ? "" : valor.ToString();
  }

  static void Responder(bool ok, Dictionary<string, string> extra, string mensaje)
  {
    var sb = new StringBuilder();
    sb.Append("{\"ok\":").Append(ok ? "true" : "false");
    sb.Append(",\"message\":").Append(Json(mensaje));
    if (extra != null)
    {
      foreach (var par in extra)
      {
        sb.Append(',').Append(Json(par.Key)).Append(':').Append(Json(par.Value));
      }
    }
    sb.Append('}');
    Console.Out.WriteLine(sb.ToString());
    Console.Out.Flush();
  }

  static string Json(string valor)
  {
    var sb = new StringBuilder("\"");
    foreach (char c in valor ?? "")
    {
      if (c == '"' || c == '\\') sb.Append('\\').Append(c);
      else if (c == '\n') sb.Append("\\n");
      else if (c == '\r') sb.Append("\\r");
      else if (c == '\t') sb.Append("\\t");
      else if (c < ' ') sb.Append("\\u").Append(((int)c).ToString("x4"));
      else sb.Append(c);
    }
    return sb.Append('"').ToString();
  }
}
