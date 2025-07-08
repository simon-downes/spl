<?php

// map of error code to pretty strings
$names = [
    E_ERROR             => 'Error',
    E_PARSE             => 'Parse Error',
    E_CORE_ERROR        => 'Core Error',
    E_CORE_WARNING      => 'Core Warning',
    E_COMPILE_ERROR     => 'Compile Error',
    E_COMPILE_WARNING   => 'Compile Warning',
    E_WARNING           => 'Warning',
    E_NOTICE            => 'Notice',
    E_USER_ERROR        => 'User Error',
    E_USER_WARNING      => 'User Warning',
    E_USER_NOTICE       => 'User Notice',
    E_RECOVERABLE_ERROR => 'Recoverable Error',
    E_DEPRECATED        => 'Deprecated',
    E_USER_DEPRECATED   => 'User Deprecated',
];

// if we don't have an error then something has gone wrong
// but we  don't want to throw an error here as that would be worse than a generic error
if (empty($error)) {
    $error = new \Exception('Unknown Error');
}

$err = [
    'name'    => get_class($error),
    'code'    => $error->getCode(),
    'message' => $error->getMessage(),
    'file'    => $error->getFile(),
    'line'    => $error->getLine(),
    'trace'   => $error->getTrace(),
    'previous' => $error->getPrevious(),
];
// if( $error instanceof \ErrorException ) {
//  $err['name'] = $names[$error->getSeverity()];
// }

// it's an error - don't send a 200 code!
if (!headers_sent()) {
    header(sprintf("%s 500 Internal Server Error", $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1'));
}

?><!DOCTYPE html>
<html>

<head>
    <meta http-equiv="content-type" content="text/html; charset=UTF-8" />
    <title><?=$err['name']?></title>
    <link rel="icon" href="/favicon.ico" type="image/vnd.microsoft.icon"/>
    <style type="text/css">

        html * {
            margin:0;
            padding:0;
            box-sizing: border-box;
        }

        body {
            font-family: Helvetica;
            color:#333333;
        }

        #header {
            padding:25px 20px 20px 20px;
            background : #eee;
        }

        #header.debug {
            background : #CD1818;
            color : #ffffff;
        }

        #header p {
            font-size:20px;
        }

        h1 {
            margin-bottom:10px;
        }

        h2 {
            margin-bottom: 10px;
            color:#328ADC;
            font-size:18px;
        }

        .message {
            padding: 1rem;
        }

        .message p {
            margin-bottom: 1rem;

        }

        #file, #trace {
            padding : 10px 20px 0px 20px;
            margin-bottom:20px;
        }

        #trace li p {
            margin-bottom:15px;
        }

        #file p,
        #trace li p:last-child {
            margin-bottom:3px;
        }

        .panel {
            background: #F1F5FB;
            padding:10px;
            border-radius:7px;
            -moz-border-radius:7px;
            -webkit-border-radius:7px;
        }

        ol {
        }

        li {
            margin: 0 0 10px 25px;
        }

        code {
            font-size:14px;
            border:1px solid #cccccc;
            padding:5px;
            border-radius:5px;
            -moz-border-radius:5px;
            -webkit-border-radius:5px;
        }

    </style>
</head>

<body>

<div id="header" class="<?=SPL_DEBUG ? 'debug' : ''?>">
    <h1><?=SPL_DEBUG ? $err['name'] : 'Internal Service Error'?></h1>
    <?php if (SPL_DEBUG) {?><p><?=$err['message']?><?= $err['code'] ? " (Code {$err['code']})" : '' ?></p><?php } ?>
</div>

<?php if (!SPL_DEBUG) {?>

<div class="message">
    <p>Alas, it's broken :(</p>
    <p>Please try again later...</p>
</div>

<?php }
else { ?>

<div id="file">

    <h2>Source File:</h2>

    <div class="panel">
        <p>
            <strong>File:</strong>
            <code><?=$err['file']?></code>&nbsp;&nbsp;
            <strong>Line: </strong>
            <code><?=$err['line']?></code>
        </p>
    </div>

</div>

<?php if ($err['trace']) { ?>
<div id="trace">
    <h2>Trace:</h2>
    <ol>
        <?php foreach ($err['trace'] as $i => $item) { ?>
        <li class="panel">
            <?php
                if (isset($item['file'])) { ?>
            <p>
                <strong>File:</strong> <code><?=$item['file']; ?></code>
                <?php if (isset($item['line'])) { ?> &nbsp;&nbsp;&nbsp;<strong>Line:</strong> <code><?=$item['line'] ?></code><?php } ?>
            </p>
            <?php } ?>
            <p><strong>Function: </strong><code><?=isset($item['class']) ? $item['class'] . $item['type'] : ''?><?=$item['function'] . '()'; ?></code></p>
        </li>
        <?php } ?>
    </ol>
</div>
<?php } ?>

<?php } ?>

</body>

</html>
