<?php
/**
 * Hyper Log
 *
 * Single file real-time log viewer and navigator
 *
 * @author Alex Matheson <alex@matho.me>
 */

/** ===============
 *   CONFIGURATION
 *  =============== */

/**
 * Log mode
 *
 * Determines how to parse and interpolate log files.
 *
 * Supported: 'plaintext', 'laravel', 'phplog'
 *
 * @var string
 */
$default_log_mode = 'plaintext';

/**
 * Log Directory
 *
 * Directory to look for logs.
 *
 * @var string
 */
$log_dir = './var/log';

/**
 * Log file regex
 *
 * Controls which files are displayed in the listing
 *
 * @var string
 */
$log_file_regex = '/^[^.].+\.log$/m';

/**
 * Default number of lines to send to the webapp
 *
 * @var int
 */
$default_num_lines = 120;

/**
 * Directory poll rate
 *
 * How often the webapp will poll the file list (in ms)
 *
 * @var int
 */
$dir_poll_rate = 1000;

/**
 * Log poll rate
 *
 * How often the webapp will poll the current log file (in ms)
 *
 * @var int
 */
$log_poll_rate = 100000;

/**
 * Whether to require basic authentication when accessing logs.
 *
 * @var bool
 */
$use_authentication = false;

/**
 * Log type processors
 *
 * Define how to process specific types of logs
 *
 * Map of regex => processor function
 *
 * @var array
 */
$log_mode_map = [
    '/laravel\.log/' => 'laravel',
    '/php.*\.log/' => 'phplog'
];

/**
 * Processes plaintext files
 *
 * @param array $in_lines [[line_no => line_text]]
 * @return array
 */
function plaintext(array $in_lines)
{
    // disable showing levels
    global $has_levels;
    $has_levels = false;

    $lines = [];
    foreach ($in_lines as $num => $line) {
        $lines[$num] = [
            'line_no' => $num,
            'text' => $line,
            'stamp' => '',
            'trace' => [],
            'level' => 'info'
        ];
    }

    return ['lines' => $lines, 'has_levels' => false, 'has_stamps' => false];
}

/**
 * Processes laravel-style logs
 *
 * @param array $in_lines [[line_no => line_text]]
 * @return array
 */
function laravel(array $in_lines)
{
    // enable showing levels
    global $has_levels;
    $has_levels = true;

    $regex = "/^\[(.+?)\] .+?\.([A-Z]+): (.*)/";

    $i = min(array_keys($in_lines));
    $lines = [];

    while (key_exists($i, $in_lines)) {
        $line = $in_lines[$i];
        $matches = [];
        if (preg_match($regex, $line, $matches)) {
            [$_, $stamp, $level, $line] = $matches;
            $lines[$i] = [
                'line_no' => $i,
                'text' => $line,
                'stamp' => $stamp,
                'trace' => [],
                'level' => strtolower($level),
                'expanded' => false
            ];
            $trace_start = $i;
            while (key_exists(++$i, $in_lines) &&
                    !preg_match($regex, $in_lines[$i])) {
                $line = $in_lines[$i];
                $lines[$trace_start]['trace'][] = [
                    'line_no' => $i,
                    'text' => $line,
                    'stamp' => $stamp,
                    'trace' => [],
                    'level' => strtolower($level)
                ];
            }
        } else {
            // unknown line
            $lines[$i] = [
                'line_no' => $i,
                'text' => $line,
                'stamp' => '',
                'trace' => [],
                'level' => ''
            ];
            $i++;
        }
    }

    return ['lines' => $lines, 'has_levels' => true, 'has_stamps' => true];
}

/**
 * Processes php logs
 *
 * @param array $in_lines [[line_no => line_text]]
 * @return array
 */
function phplog(array $in_lines)
{
    // enable showing levels
    global $has_levels;
    $has_levels = true;

    $error_regex = "/^\[(.+?)\] PHP (.+?): (.*)/";
    $normal_regex = "/^\[(.+?)\] PHP (.*)/";

    $i = min(array_keys($in_lines));
    $lines = [];

    while (key_exists($i, $in_lines)) {
        $line = $in_lines[$i];
        $matches = [];
        if (preg_match($error_regex, $line, $matches)) {
            [$_, $stamp, $level, $line] = $matches;
            $lines[$i] = [
                'line_no' => $i,
                'text' => $line,
                'stamp' => $stamp,
                'trace' => [],
                'level' => strtolower($level),
                'expanded' => false
            ];
            $trace_start = $i;
            while (key_exists(++$i, $in_lines) &&
                    !preg_match($error_regex, $in_lines[$i])) {
                $line = $in_lines[$i];
                $lines[$trace_start]['trace'][] = [
                    'line_no' => $i,
                    'text' => $line,
                    'stamp' => $stamp,
                    'trace' => [],
                    'level' => strtolower($level)
                ];
            }
        } elseif (preg_match($normal_regex, $line, $matches)) {
            [$_, $stamp, $line] = $matches;
            // unknown line
            $lines[$i] = [
                'line_no' => $i,
                'text' => $line,
                'stamp' => $stamp,
                'trace' => [],
                'level' => ''
            ];
            $i++;
        } else {
            // unknown line
            $lines[$i] = [
                'line_no' => $i,
                'text' => $line,
                'stamp' => '',
                'trace' => [],
                'level' => ''
            ];
            $i++;
        }
    }

    return ['lines' => $lines, 'has_levels' => true, 'has_stamps' => true];
}

/** ===================
 *   END CONFIGURATION
 *  =================== */

// redirect to hl.php/
if (substr($_SERVER['REQUEST_URI'], -1) !== '/') {
    header("Location: {$_SERVER['REQUEST_URI']}/");
    exit;
}

/**
 * Get lines from given file, from $start to $end (inclusive)
 *
 * @param integer $start
 * @param integer $end
 * @return array [[line_no => line_text]]
 */
function get_lines(string $file, int $start = null, int $end = null)
{
    global $default_num_lines;

    $lc = (int) `grep -c '' $file`;

    // default to default_num_lines from end of file
    $start = $start ?? $lc - $default_num_lines;
    $end = $end ?? $start + $default_num_lines;

    if (--$start < 1) $start = 1;
    if ($end > $lc) $end = $lc;

    $len = ($end - $start + 1) * 2;
    $cmd = "sed -n '=;{$start},{$end}p;{$end}q;a \
    !line!' $file | tail -n{$len}\n";
    // print $cmd;
    $lines = explode("!line!", `$cmd`);
    $output = [];
    foreach ($lines as $line) {
        if ($line === '') continue;
        [$line_no, $line] = explode("\n", $line, 2);
        $output[$line_no] = trim($line);
    }
    return $output;
}

/**
 * Get the processor name for the file
 *
 * @param string $file
 * @return string
 */
function get_processor_func(string $file): string
{
    global $log_mode_map;
    global $default_log_mode;

    foreach ($log_mode_map as $regex => $processor) {
        if (preg_match($regex, $file)) {
            return $processor;
        }
    }
    return $default_log_mode;
}

// die(json_encode(get_lines($argv[1], $argv[2], $argv[3]), JSON_PRETTY_PRINT));

function dd($data, $pre = false)
{
    if ($pre) {
        die('<pre>' . json_encode($data, JSON_PRETTY_PRINT) . '</pre>');
    }
    die(json_encode($data));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /**
     * POST request
     *
     * Retrieve log data
     */
    $request = json_decode(file_get_contents('php://input'));

    function respond($data, $status = 200)
    {
        global $request;
        http_response_code($status);
        dd(array_merge((array) $request, $data));
    }

    if ($request->wants === 'filenames') {
        /**
         * Get all log file names
         */

        $files = array_filter(scandir($log_dir), function ($file) use ($log_file_regex) {
            return !!preg_match($log_file_regex, $file);
        });

        respond(['files' => array_values($files)]);
    }

    if ($request->wants === 'log') {
        /**
         * Read a log file
         */

        // filter bad file names
        if (!preg_match($log_file_regex, $request->file)) {
            respond(['error' => 'Invalid file name'], 400);
        }

        $lines = get_lines("{$log_dir}/{$request->file}", $request->start, $request->end);
        respond(get_processor_func($request->file)($lines));
    }

    respond(['error' => 'Unknown request'], 400);

} else {
    /**
     * GET request
     *
     * Build HTML page
     */
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Landing &middot; Hyperlog</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://unpkg.com/hyperhtml@latest/min.js"></script>
</head>
<body>
    <header>
        <ul id="files" class="tab tab-block">
            <li class="tab-item active">
                <a>Loading...</a>
            </li>
        </ul>
    </header>
    <main>
        <table class="table table-hover">
            <tbody id="log">
                <tr>
                    <td class="mono">Loading...</td>
                </tr>
            </tbody>
        </table>
    </main>
</body>
<script type="text/javascript">
(() => {
    const pollLogRate = <?php echo $log_poll_rate; ?>;
    const pollFilesRate = <?php echo $dir_poll_rate; ?>;
    const { wire, bind } = hyperHTML;
    const $ = document.querySelector.bind(document);
    let state = { file: null, start: null, end: null, files: null };
    let log = { file: null, lines: {} };
    // check status of a response
    const check = (res) => res.status >= 200 && res.status < 300
        ? Promise.resolve(res.json())
        : Promise.reject(new Error(res.statusText));
    // make a request with json data
    const request = (json) => fetch(new Request('', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(json)
    })).then(check);
    // fetch list of files names from server
    const fetchFilenames = () => request({ wants: 'filenames' })
        .then(({ files }) => {
            files = files.map(file => ({ name: file }));
            if (!state.file && files.length > 0) {
                go({ file: files[0].name, files });
            } else {
                go({ files })
            }
        });
    // fetch log content
    const fetchLog = () => request({
            wants: 'log', file: state.file, start: state.start, end: state.end
        })
        .then((data) => {
            if (log.file !== state.file) {
                log = { file: state.file, ...data };
            } else {
                // log.lines = log.lines.concat(lines);
            }
            go({ start: logStart() })
            renderLog();
            setTimeout(() => {
                const lastLine = document.querySelector('tr.line:last-child');
                scrollTo(0, lastLine.offsetTop)
            }, 0);
        })
    const logLines = () => Object.values(log.lines).map(line => parseInt(line.line_no))
    const logStart = () => Math.min(...logLines())
    const logEnd = () => Math.min(...logLines())
    // update state and re-render if necessary
    const go = (newState = {}) => {
        const prev = state;
        state = { ...state, ...newState };
        // don't update if no change to state
        if (JSON.stringify(prev) === JSON.stringify(state)) return;

        const newFile = state.file !== prev.file;
        const newFileList = JSON.stringify(state.files) !== JSON.stringify(prev.files);
        if (newFile) {
            state.start = null;
            state.end = null;
            if (!state.files.map(file => file.name).includes(state.file)) {
                state.file = null;
            }
            document.title = `${state.file} • Hyperlog`;
        } else {
            state.end = logEnd()
        }
        location.hash = '#/';
        if (state.file) {
            location.hash += state.file;
            if (state.start) {
                location.hash += `/${state.start}`;
            }
        }
        console.log('state', state);
        if (newFile || newFileList) requestAnimationFrame(renderTabs);
        if (newFile || prev.start !== state.start) fetchLog();
    }
    // active class for file names
    const active = (file) => location.hash.includes(`/${file}`) ? 'active' : '';
    // render header tabs
    const renderTabs = () => {
        const { files } = state;
        console.log('render tabs')
        bind($('#files'))`
            ${files.map(file => wire(file)`
                <li class=${`tab-item ${active(file.name)}`}>
                    <a
                        href="javascript:void(0)"
                        onclick=${e => go({ file: file.name })}
                    >
                        ${file.name}
                    </a>
                </li>
            `)}
            ${files.length === 0 && wire()`
                <li class="tab-item"><a>No logs found</a></li>
            ` || ''}
            <li class="tab-item tab-action">

            </li>
        `;
    };
    // render log lines
    const renderLog = () => {
        const { lines, has_levels } = log;
        bind($('#log'))`
            ${Object.values(lines).map(line => wire(line)`
                <tr id=${`line-${line.line_no}`} class=${`line ${line.level}`}>
                    <td class="mono number">${line.line_no}</td>
                    ${has_levels && wire(line)`
                        <td class="mono level">${line.level}</td>
                    ` || ''}
                    <td
                        class=${`mono line-text ${
                            (line.trace.length > 0 ? 'has-trace' : '') +
                            (line.expanded ? ' open' : '')
                        }`}
                        onclick=${() => {
                            if (line.trace.length > 0) {
                                line.expanded = !line.expanded
                                renderLog()
                            }
                        }}
                    >${line.text}</td>
                </tr>
                ${line.expanded && line.trace.map(line => wire(line)`
                    <tr id=${`line-${line.line_no}`} class="line trace">
                        <td class="mono number">${line.line_no}</td>
                        ${has_levels && wire(line)`
                            <td class="mono level"></td>
                        ` || ''}
                        <td class="mono line-text">${line.text}</td>
                    </tr>
                `) || ''}
            `)}
        `
    };
    // request listing from api
    (() => {
        const hashParts = location.hash.split('/');
        if (hashParts.length > 1) {
            state.file = hashParts[1];
            document.title = `${state.file} • Hyperlog`;
            fetchLog();
        }
    })();
    fetchFilenames();
    setInterval(fetchFilenames, pollFilesRate);
})();
</script>
<style>
body {
    font-family: -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        Roboto,
        "Helvetica Neue",
        Arial,
        sans-serif;
    background: #2f394a;
    color: #8690a7;
    padding: 0;
    margin: 0;
}
header {
    position: fixed;
    background: white;
    width: 100%;
    top: 0;
    left: 0;
    z-index: 1;
}
.tab.tab-block {
    list-style-type: none;
    margin: 0;
    padding: 0;
    display: flex;
}
.tab-item {
    border-bottom: 2px solid transparent;
    flex-grow: 1;
}
.tab-item > a {
    display: block;
    color: #262e3a;
    padding: 12px 16px;
    text-decoration: none;
    width: 100%;
    text-align: center;
}
.tab-item.active {
    border-bottom-color: #5755d9;
}
.tab-item.active > a {
    color: #5755d9;
}
main {
    margin-top: 45px;
    padding: 12px 0;
    overflow: auto;
}
.table {
    width: 100%;
}
.line:hover {
    background: #354053;
    color: #e1e7ec;
}
.line td {
    padding: 4px 8px;
    vertical-align: top;
}
.line.error {
    color: #EF5753;
}
.line.error:hover {
    color: #F9ACAA;
}
.line.warning {
    color: #FAAD63;
}
.line.warning:hover {
    color: #FCD9B6;
}
.line .number {
    color: #8690a7;
}
.line .level {
    text-align: center;
}
.line .line-text.has-trace {
    cursor: pointer;
    padding-left: 32px;
    position: relative;
}
.line .line-text.has-trace:before {
    content: '⇣';
    position: absolute;
    left: 12px;
}
.line .line-text.has-trace.open:before {
    content: '⇡';
}
.line.trace .line-text {
    padding-left: 48px;
}
.mono {
    font-family:
        "Roboto Mono",
        "SF Mono",
        "Monaco",
        "Inconsolata",
        "Fira Mono",
        "Droid Sans Mono",
        "Source Code Pro",
        monospace;
    font-size: 14px;
}
.line-no {
}
</style>
</html>
<?php
}