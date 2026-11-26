// Exponential Velocity (vc) as a Windows service.
//
// PHP cannot answer the Service Control Manager itself (php.exe registered as
// a service is killed after 30 seconds with error 1053), so this small host
// does: the SCM starts it, it starts qbixserver.php with php.exe, and it turns
// the SCM's Stop and Shutdown into Velocity's own graceful stop -- the request
// file "<pidfile>.stop", which the server picks up within a second, exactly
// as "qbixserver --stop" does on Windows. A server that does not stop within
// the grace period is ended with its workers (taskkill /T /F).
//
// If the server exits on its own, the host exits with an error, so the SCM's
// recovery actions (vcc.ps1 install sets "restart after 5 s") start it again.
// Start, stop and unexpected exits are written to the Application event log
// under the source given by --event-source (registered by vcc.ps1 install).
//
// Built on the target machine by vcc.ps1 with the C# compiler that ships with
// the .NET Framework 4.x in every supported Windows (csc.exe), so nothing has
// to be downloaded and no binary is kept in the repository. AnyCPU: runs as a
// native process on x86, x64 and ARM64.
//
// Command line (the service's ImagePath, written by vcc.ps1):
//   VcService.exe --name vc --php C:\php\php.exe --pid C:\ProgramData\vc\run\vc.pid
//                 --log C:\ProgramData\vc\logs\server.log [--event-source vc]
//                 [--tmp C:\ProgramData\vc\run\tmp] [--grace 25] [--workdir DIR]
//                 -- <qbixserver.php and its options>
//   VcService.exe --console ...   runs the same in the foreground (CTRL+C stops it)

using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.ServiceProcess;
using System.Text;
using System.Threading;

namespace ExponentialVelocity
{
    public class VcService : ServiceBase
    {
        string php, pidFile, logFile, eventSource, tmpDir, workDir;
        int grace = 25;
        List<string> serverArgs = new List<string>();
        Process server;
        volatile bool stopping;
        readonly object logLock = new object();
        StreamWriter log;

        public static int Main(string[] args)
        {
            VcService s = new VcService();
            string error = s.Parse(args);
            if (error != null) { Console.Error.WriteLine("VcService: " + error); return 2; }
            if (Array.IndexOf(args, "--console") >= 0)
            {
                s.Begin();
                ManualResetEvent done = new ManualResetEvent(false);
                Console.CancelKeyPress += delegate(object o, ConsoleCancelEventArgs e) { e.Cancel = true; s.End(); done.Set(); };
                s.server.WaitForExit();
                done.WaitOne(2000);
                return s.server.HasExited ? s.server.ExitCode : 0;
            }
            ServiceBase.Run(s);
            return 0;
        }

        string Parse(string[] args)
        {
            for (int i = 0; i < args.Length; i++)
            {
                string a = args[i];
                if (a == "--") { for (int j = i + 1; j < args.Length; j++) serverArgs.Add(args[j]); break; }
                if (a == "--console") continue;
                if (i + 1 >= args.Length) return "missing value for " + a;
                string v = args[++i];
                switch (a)
                {
                    case "--name": ServiceName = v; break;
                    case "--php": php = v; break;
                    case "--pid": pidFile = v; break;
                    case "--log": logFile = v; break;
                    case "--event-source": eventSource = v; break;
                    case "--tmp": tmpDir = v; break;
                    case "--workdir": workDir = v; break;
                    case "--grace": grace = int.Parse(v); break;
                    default: return "unknown option " + a;
                }
            }
            if (string.IsNullOrEmpty(ServiceName)) ServiceName = "vc";
            if (string.IsNullOrEmpty(php)) return "--php is required";
            if (string.IsNullOrEmpty(pidFile)) return "--pid is required";
            if (serverArgs.Count == 0) return "the server's command line follows --";
            CanStop = true;
            CanShutdown = true;
            AutoLog = false;
            return null;
        }

        protected override void OnStart(string[] args)
        {
            RequestAdditionalTime(30000);
            Begin();
        }

        protected override void OnStop() { RequestAdditionalTime((grace + 10) * 1000); End(); }

        protected override void OnShutdown() { RequestAdditionalTime((grace + 10) * 1000); End(); }

        static string Quote(string a)
        {
            if (a.Length > 0 && a.IndexOfAny(new char[] { ' ', '\t', '"' }) < 0) return a;
            // CommandLineToArgvW rules: backslashes double only before a quote.
            StringBuilder b = new StringBuilder("\"");
            int slashes = 0;
            foreach (char c in a)
            {
                if (c == '\\') { slashes++; continue; }
                if (c == '"') { b.Append('\\', slashes * 2 + 1); b.Append('"'); slashes = 0; continue; }
                b.Append('\\', slashes); slashes = 0; b.Append(c);
            }
            b.Append('\\', slashes * 2);
            b.Append('"');
            return b.ToString();
        }

        void Log(string line)
        {
            lock (logLock)
            {
                if (log == null) return;
                try { log.WriteLine(line); log.Flush(); } catch (Exception) { }
            }
        }

        void Event(string message, EventLogEntryType type)
        {
            Log(DateTime.Now.ToString("yyyy-MM-dd HH:mm:ss") + " [service] " + message);
            if (string.IsNullOrEmpty(eventSource)) return;
            try { EventLog.WriteEntry(eventSource, message, type); } catch (Exception) { }
        }

        void Begin()
        {
            if (!string.IsNullOrEmpty(logFile))
            {
                Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(logFile)));
                log = new StreamWriter(new FileStream(logFile, FileMode.Append, FileAccess.Write, FileShare.ReadWrite | FileShare.Delete), new UTF8Encoding(false));
            }
            try { File.Delete(pidFile + ".stop"); } catch (Exception) { }
            StringBuilder cmd = new StringBuilder();
            foreach (string a in serverArgs) { if (cmd.Length > 0) cmd.Append(' '); cmd.Append(Quote(a)); }
            ProcessStartInfo si = new ProcessStartInfo(php, cmd.ToString());
            si.UseShellExecute = false;
            si.CreateNoWindow = true;
            si.RedirectStandardOutput = true;
            si.RedirectStandardError = true;
            si.RedirectStandardInput = true;
            si.WorkingDirectory = !string.IsNullOrEmpty(workDir) ? workDir : Path.GetDirectoryName(Path.GetFullPath(php));
            if (!string.IsNullOrEmpty(tmpDir))
            {
                Directory.CreateDirectory(tmpDir);
                si.EnvironmentVariables["TEMP"] = tmpDir;
                si.EnvironmentVariables["TMP"] = tmpDir;
            }
            si.EnvironmentVariables["VC_SERVICE"] = ServiceName;
            server = new Process();
            server.StartInfo = si;
            server.EnableRaisingEvents = true;
            server.OutputDataReceived += delegate(object o, DataReceivedEventArgs e) { if (e.Data != null) Log(e.Data); };
            server.ErrorDataReceived += delegate(object o, DataReceivedEventArgs e) { if (e.Data != null) Log(e.Data); };
            server.Exited += OnServerExited;
            server.Start();
            server.BeginOutputReadLine();
            server.BeginErrorReadLine();
            Event("started: " + php + " " + cmd + " (pid " + server.Id + ", account " + Environment.UserDomainName + "\\" + Environment.UserName + ")", EventLogEntryType.Information);
        }

        void OnServerExited(object sender, EventArgs e)
        {
            if (stopping) return;
            int code = -1;
            try { code = server.ExitCode; } catch (Exception) { }
            Event("the server exited on its own (exit code " + code + "); the service stops with an error so its recovery actions restart it", EventLogEntryType.Error);
            lock (logLock) { if (log != null) { try { log.Dispose(); } catch (Exception) { } log = null; } }
            // A non-zero exit of the service process is a failure to the SCM, which runs the recovery actions.
            Environment.Exit(1);
        }

        void End()
        {
            if (stopping) return;
            stopping = true;
            if (server == null || server.HasExited) { Event("stopped", EventLogEntryType.Information); return; }
            // Velocity's graceful stop on Windows: the request file beside the pid file.
            try { File.WriteAllText(pidFile + ".stop", DateTime.UtcNow.ToString("o")); }
            catch (Exception ex) { Event("could not write " + pidFile + ".stop: " + ex.Message, EventLogEntryType.Warning); }
            bool gone = server.WaitForExit(grace * 1000);
            if (!gone)
            {
                Event("the server did not stop within " + grace + " s; ending it and its workers", EventLogEntryType.Warning);
                try
                {
                    ProcessStartInfo k = new ProcessStartInfo("taskkill.exe", "/PID " + server.Id + " /T /F");
                    k.UseShellExecute = false; k.CreateNoWindow = true;
                    Process.Start(k).WaitForExit(10000);
                }
                catch (Exception) { }
                server.WaitForExit(5000);
            }
            Event(gone ? "stopped gracefully" : "stopped (forced)", gone ? EventLogEntryType.Information : EventLogEntryType.Warning);
            lock (logLock) { if (log != null) { try { log.Dispose(); } catch (Exception) { } log = null; } }
        }
    }
}
