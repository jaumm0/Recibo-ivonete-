// RecibosLauncher.cs
// Launcher em C# que sobe o servidor embutido do PHP e abre o gerador de
// recibos numa janela tipo app (Edge em modo --app). Compilado para
// Recibos.exe com csc.exe (não requer nada além do .NET Framework, que já
// vem no Windows). O usuário só dá dois cliques — não digita URL nem abre
// terminal. Ao fechar a janela, o PHP é encerrado.

using System;
using System.Diagnostics;
using System.IO;
using System.Management;
using System.Net.NetworkInformation;
using System.Net.Sockets;
using System.Windows.Forms;

internal static class Program
{
    private const string IndexPage = "index.php";

    [STAThread]
    private static int Main(string[] args)
    {
        string appDir = AppDomain.CurrentDomain.BaseDirectory;

        // Permite passar a pasta do app por argumento (uso futuro); por
        // padrão é a pasta onde o .exe está.
        if (args.Length > 0 && Directory.Exists(args[0]))
            appDir = Path.GetFullPath(args[0]);

        // 1) Localiza o php.exe (PHP embarcado na pasta, PATH ou local comum).
        string php = FindPhp(appDir);
        if (php == null)
        {
            MessageBox.Show(
                "Não encontrei o PHP neste computador.\n\n" +
                "Instale o PHP (ex.: 'winget install PHP.PHP.8.4') e tente novamente.",
                "Recibos — PHP ausente",
                MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        // 2) Garante que o index.php exista na pasta.
        if (!File.Exists(Path.Combine(appDir, IndexPage)))
        {
            MessageBox.Show(
                "Não encontrei o 'index.php' em:\n" + appDir +
                "\n\nColoque o Recibos.exe na pasta do projeto.",
                "Recibos — pasta incorreta",
                MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        // 3) Escolhe uma porta TCP livre.
        int port = FreeTcpPort();

        // 4) Sobe o servidor embutido do PHP escondido.
        var psi = new ProcessStartInfo(php, "-S 127.0.0.1:" + port + " -t \"" + appDir + "\"")
        {
            UseShellExecute = false,
            CreateNoWindow = true,
            WindowStyle = ProcessWindowStyle.Hidden,
            WorkingDirectory = appDir,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };
        Process phpProc;
        try
        {
            phpProc = Process.Start(psi);
        }
        catch (Exception ex)
        {
            MessageBox.Show("Não foi possível iniciar o PHP:\n" + ex.Message,
                "Recibos", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        // Log de erros do PHP numa arquivo (útil se algo der errado).
        string logFile = Path.Combine(Path.GetTempPath(), "recibos_php.log");
        phpProc.ErrorDataReceived += (s, e) =>
        {
            if (!string.IsNullOrEmpty(e.Data))
                File.AppendAllText(logFile,
                    "[" + DateTime.Now.ToString("HH:mm:ss") + "] " + e.Data + Environment.NewLine);
        };
        phpProc.BeginErrorReadLine();

        // 5) Aguarda o servidor responder.
        string url = "http://127.0.0.1:" + port + "/";
        if (!WaitForServer(port, TimeSpan.FromSeconds(15)))
        {
            try { phpProc.Kill(); } catch { }
            string log = File.Exists(logFile) ? File.ReadAllText(logFile) : "";
            MessageBox.Show(
                "O servidor PHP não respondeu a tempo.\n\nLog:\n" + log,
                "Recibos", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        // 6) Abre o Edge em modo app (janela limpa, sem abas/endereço).
        string edge = FindEdge();
        if (edge == null)
        {
            try { phpProc.Kill(); } catch { }
            MessageBox.Show("Não encontrei o Microsoft Edge.",
                "Recibos", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }
        // Perfil do Edge ÚNICO por execução (usa a porta). Assim o launcher
        // não confunde a janela atual com janelas de execuções anteriores, e
        // funciona mesmo se o Edge já estiver aberto (o Edge faz "handoff"
        // para a sessão existente e o processo disparado encerra na hora —
        // por isso não esperamos nele, mas nos processos com este perfil).
        string dataDir = Path.Combine(Path.GetTempPath(), "recibos_edge_" + port);

        var edgeArgs = "--app=" + url +
                       " --window-size=1120,860" +
                       " --user-data-dir=\"" + dataDir + "\"" +
                       " --no-first-run --no-default-browser-check";

        var edgePsi = new ProcessStartInfo(edge, edgeArgs)
        {
            UseShellExecute = false,
        };
        try
        {
            Process.Start(edgePsi);
        }
        catch (Exception ex)
        {
            try { phpProc.Kill(); } catch { }
            MessageBox.Show("Não foi possível abrir o Edge:\n" + ex.Message,
                "Recibos", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        // 7) Aguarda a janela do app aparecer (tolerância de 20s). Se não
        //    aparecer, encerra o PHP.
        var grace = DateTime.UtcNow + TimeSpan.FromSeconds(20);
        while (DateTime.UtcNow < grace && !HasEdgeAppProcess(dataDir))
            System.Threading.Thread.Sleep(500);
        if (!HasEdgeAppProcess(dataDir))
        {
            try { phpProc.Kill(); } catch { }
            MessageBox.Show("A janela do app não abriu. Veja recibos_php.log.",
                "Recibos", MessageBoxButtons.OK, MessageBoxIcon.Error);
            return 1;
        }

        // 8) Mantém o launcher vivo enquanto a janela do app estiver aberta.
        //    Monitora os processos do Edge com o nosso perfil (via WMI).
        //    Quando o usuário fecha a janela, eles somem e encerramos o PHP.
        while (HasEdgeAppProcess(dataDir))
            System.Threading.Thread.Sleep(1000);

        try { if (!phpProc.HasExited) phpProc.Kill(); } catch { }
        try { Directory.Delete(dataDir, true); } catch { }
        return 0;
    }

    /**
     * Verifica se existe algum processo msedge.exe em execução cuja linha
     * de comando contenha o user-data-dir desta execução — ou seja, se a
     * janela do app ainda está aberta. Em caso de erro no WMI, retorna true
     * (seguro: não mata o PHP prematuramente).
     */
    private static bool HasEdgeAppProcess(string dataDir)
    {
        try
        {
            using (var searcher = new ManagementObjectSearcher(
                "SELECT CommandLine FROM Win32_Process WHERE Name='msedge.exe'"))
            {
                foreach (var mo in searcher.Get())
                {
                    string cl = mo["CommandLine"] as string;
                    if (cl != null && cl.Contains(dataDir))
                        return true;
                }
            }
            return false;
        }
        catch
        {
            return true; // não arriscar matar o PHP
        }
    }

    /** Procura o php.exe: primeiro o PHP embarcado (pasta php/ do projeto,
     *  portátil), depois no PATH e em locais comuns do winget. */
    private static string FindPhp(string appDir)
    {
        // 1) PHP embarcado na pasta do projeto (portátil) — primeira opção,
        //    permite copiar a pasta pra outra máquina e rodar sem instalar PHP.
        string bundled = Path.Combine(appDir, "php", "php.exe");
        if (File.Exists(bundled)) return bundled;

        // 2) PATH do sistema
        string pathEnv = Environment.GetEnvironmentVariable("PATH");
        string[] parts = pathEnv != null ? pathEnv.Split(';') : new string[0];
        foreach (var p in parts)
        {
            if (string.IsNullOrWhiteSpace(p)) continue;
            string full = Path.Combine(p.Trim('"'), "php.exe");
            if (File.Exists(full)) return full;
        }
        // Local comum do winget
        string[] candidatos = {
            @"C:\Users\" + Environment.UserName +
                @"\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe",
            @"C:\PHP\php.exe",
            @"C:\xampp\php\php.exe",
        };
        foreach (var c in candidatos)
            if (File.Exists(c)) return c;
        return null;
    }

    /** Procura o msedge.exe em locais conhecidos. */
    private static string FindEdge()
    {
        string[] candidatos = {
            @"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
            @"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
        };
        foreach (var c in candidatos)
            if (File.Exists(c)) return c;
        return null;
    }

    /** Devolve uma porta TCP livre. */
    private static int FreeTcpPort()
    {
        var l = new TcpListener(System.Net.IPAddress.Loopback, 0);
        l.Start();
        int port = ((System.Net.IPEndPoint)l.LocalEndpoint).Port;
        l.Stop();
        return port;
    }

    /** Aguarda até que a porta aceite conexão (servidor pronto). */
    private static bool WaitForServer(int port, TimeSpan timeout)
    {
        var deadline = DateTime.UtcNow + timeout;
        while (DateTime.UtcNow < deadline)
        {
            try
            {
                using (var c = new TcpClient())
                {
                    var ar = c.BeginConnect("127.0.0.1", port, null, null);
                    bool ok = ar.AsyncWaitHandle.WaitOne(800);
                    if (ok)
                    {
                        c.EndConnect(ar);
                        return true;
                    }
                }
            }
            catch { }
            System.Threading.Thread.Sleep(150);
        }
        return false;
    }
}