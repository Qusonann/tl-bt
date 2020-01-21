<?php

if (!isset($argv[1])) {
    echo <<<MSG
Usage: php gen_tl.php input.json [filterType] > output.bt
MSG;
    die();
}

$filterType = null;
if (isset($argv[2])) {
    $filterType = $argv[2];
}

/**
 * @param string $filename
 * @return arrray [constructors, methods]
 */
function getJson($filename) {
    global $isDebug;
    if ($isDebug) {
        echo "load $filename\n";
    }
    $json = json_decode(file_get_contents($filename), true);
    if (!$json) {
        fprintf(STDERR, "invalid json $filename\n");
        exit(1);
    }

    $constructors = $json['constructors'];
    $methods = $json['methods'];
    $constructorsById = [];
    $methodsById = [];
    foreach ($constructors as $constructor) {
        $constructorsById[$constructor['id']] = $constructor;
    }
    foreach ($methods as $method) {
        $methodsById[$method['id']] = $method;
    }
    asort($constructorsById);
    asort($methodsById);
    $json['constructors'] = $constructorsById;
    $json['methods'] = $methodsById;
    return $json;
}

$spec = getJson($argv[1]);
$predicatesByType = [];
$tab = '    ';
$flagPrefix = 'b_';
foreach ($spec['constructors'] as $ctor) {
    $predicate = str_replace('.', '__', $ctor['predicate']);
    $dechex = dechex($ctor['id'] + 0);
    $hexId = $dechex;
    if (strlen($dechex) > 8) {
        $hexId = substr($dechex, -8, 8);
    }
    if ($filterType && isset($ctor['type']) && $ctor['type'] != $filterType) {
        continue;
    }
    echo "typedef struct _$predicate { // 0x$hexId\n";
    $flags = [];
    $fields = [];
    foreach ($ctor['params'] as $param) {
        $type = '';
        $prefix = '';
        if (strpos($param['type'], 'flags.') !== false) {
            $flagRx = '/flags\.(?P<flagId>\d+)\?/';
            $oldType = $param['type'];
            $param['type'] = preg_replace($flagRx, '', $param['type']);
            if (preg_match($flagRx, $oldType, $matches)) {
                $flagId = (int) $matches['flagId'];
                $flags[$flagId] = $param['name'];
                $prefix = "{$tab}if ($flagPrefix{$param['name']})\n$tab";
            }
        }
        if (strpos($param['type'], 'Vector<')) {
            //TODO gen vector type?
        }
        switch ($param['type']) {
            case '#':
            break;
            $type = "int";
            break;
            case 'int':
            $type = "int";
            break;
            case 'long':
            $type = "int64";
            break;
            case 'string':
            $type = "TLString";
            break;
            case 'true':
            case 'false':
            case 'bool':
            case 'Bool':
            $type = 'TLBool';
            break;
            default:
            // TODO check struct exists
            $type = "{$param['type']}";
            break;

        }
        if ($type) {
            $fields[] = "$prefix$tab$type {$param['name']};\n";
        }
    }
    if ($flags) {
        $flagLines = [];
        $emptyBits = 0;
        for ($i = 0; $i < 32; ++$i) {
            if (isset($flags[$i])) {
                if ($emptyBits) {
                    $flagLines[] = "{$tab}int : $emptyBits;\n";
                    $emptyBits = 0;
                }
                $flagLines[] = "{$tab}int $flagPrefix{$flags[$i]} : 1; // $i\n";
            } else {
                ++$emptyBits;
            }
        }
        if ($emptyBits) {
            $flagLines[] = "{$tab}int : $emptyBits;\n";
            $emptyBits = 0;
        }
        foreach($flagLines as $flagLine) {
            echo "$flagLine";
        }
    }
    foreach($fields as $field) {
        echo $field;
    }
    echo "};\n\n";
    if (!isset($ctor['type'])) {
        continue;
    }
    if (!isset($predicatesByType[$ctor['type']])) {
        $predicatesByType[$ctor['type']] = [];
    }
    $predicatesByType[$ctor['type']] []= $ctor;
}

foreach ($predicatesByType as $type => $predicates) {
    $type = str_replace('.', '__', $type);
    echo "typedef struct $type {\n";
    echo "{$tab}int id;\n";
    $else = false;
    foreach ($predicates as $predicate) {
        $clause = $else ? 'else if' : 'if';
        echo "$tab$clause (id == {$predicate['id']})\n";
        $typename = str_replace('.', '__', $predicate['predicate']);
        echo "$tab{$tab}_$typename val;\n";
        $else = true;
    }
    echo "{$tab}else\n";
    echo "$tab{$tab}Exit(1);\n";
    echo "};\n\n";
}
