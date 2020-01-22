<?php
declare(strict_types=1);

if (!isset($argv[1]) || !isset($argv[2])) {
    echo <<<MSG
Usage: php gen_tl.php input.json filterType [recursive=true]
MSG;
    die();
}

$filterType = $argv[2];
$recursive = true;
if (isset($argv[3]) && ($argv[3] === 'false' || $argv[3] === '0')) {
    $recursive = false;
}

$output = "";
/**
 * @param string $filename
 * @return array [constructors, methods]
 */
function getJson($filename) {
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

function genText(array &$spec, string $filterType, bool $recursive = true): bool {
    static $stack = [];
    $predicatesByType = [];
    $tab = '    ';
    $flagPrefix = 'b_';
    $filename = "struct_" . strtolower(str_replace('.', '__', $filterType)).".bt";
    if (file_exists($filename)) {
        return false;
    }
    if (in_array($filterType, $stack)) {
        die("Preventing recursion for $filterType, please run non-recursively\n");
    }
    $stack[] = $filterType;
    echo "generating $filename\n";
    $lines = [];
    $type_ = strtoupper(str_replace('.', '__', $filterType));
    $preamble = "#ifndef __STRUCT_{$type_}__\n";
    $preamble .= "#define __STRUCT_{$type_}__\n";
    $includes = [];

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
        $lines[]= "typedef struct _$predicate { // 0x$hexId\n";
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
            $baseType = null;
            if (strpos($param['type'], 'Vector<') !== false) {
                $rx = '/Vector\<(?P<type>[A-Za-z_]+)\>/';
                $baseType = '';
                if (preg_match($rx, $param['type'], $matches)) {
                    $baseType = $matches['type'];
                    if ($baseType == 'int' || $baseType == 'string' || $baseType == 'long') {
                        $baseType = '';
                    }
                }
                $param['type'] = str_replace('<int>', '<Int>', $param['type']);
                $param['type'] = str_replace('<long>', '<Long>', $param['type']);
                $param['type'] = str_replace('<string>', '<String>', $param['type']);
                $param['type'] = str_replace('>', '', $param['type']);
                $param['type'] = str_replace('<', '', $param['type']);
            }
            $isComplex = false;
            switch ($param['type']) {
                case 'false':
                case 'true':
                case '#':
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
                case 'bool':
                case 'Bool':
                $type = 'TLBool';
                break;
                default:
                if ($param['type'] != 'bytes' && $param['type'] != 'double') {
                    $isComplex = true;
                }
                // TODO check struct exists
                $type = $param['type'];
                break;

            }
            if ($param['type'] === 'true' || $param['type'] === 'false') {
                continue;
            }
            if ($baseType) {
                $bt = strtolower($baseType);
                if ($baseType != $filterType) {
                    $includes[] = "#include \"struct_$bt.bt\"\n";
                    if ($recursive) {
                        if (genText($spec, $baseType)) {
                            array_pop($stack);
                        }
                    }
                } else {
                    $includes[] = "struct Vector$baseType\n";
                }
            } else if ($isComplex) {
                $lowerType = strtolower($type);
                if ($type != $filterType) {
                    $includes[] = "#include \"struct_$lowerType.bt\"\n";
                    if ($recursive) {
                        if (genText($spec, $type)) {
                            array_pop($stack);
                        }
                    }
                } else {
                    $includes[] = "struct $baseType\n";
                }
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
            }
            foreach($flagLines as $flagLine) {
                $lines[]= "$flagLine";
            }
        }
        foreach($fields as $field) {
            $lines[]= $field;
        }
        $lines[]= "};\n\n";
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
        $lines[]= "typedef struct $type {\n";
        $lines[]= "{$tab}int id;\n";
        $else = false;
        foreach ($predicates as $predicate) {
            $clause = $else ? 'else if' : 'if';
            $lines[]= "$tab$clause (id == {$predicate['id']})\n";
            $typename = str_replace('.', '__', $predicate['predicate']);
            $lines[]= "$tab{$tab}_$typename val;\n";
            $else = true;
        }
        $lines[]= "{$tab}else {\n";
        $lines[]= "$tab{$tab}Printf(\"Invalid id %d for $type\", id);\n";
        $lines[]= "$tab{$tab}Exit(1);\n";
        $lines[]= "$tab}\n";
        $lines[]= "};\n\n";
        $lines[]= <<<MSG
    typedef struct Vector$type {
        int id;
        if (id != 0x1CB5C415) {
            Printf("Invalid id %d for Vector$type\\n", id);
            Exit(1);
        }
        int size;
        $type items[size] <optimize=false>;
    };

    MSG;
    }
    if($filterType) {
        $lines[] = "#endif\n";
    }

    $imploded = implode("", $lines);
    $includes = array_unique($includes);
    $includesImploded = implode("", $includes);
    file_put_contents($filename, $preamble . $includesImploded . $imploded);
    return true;
}

genText($spec, $filterType, $recursive);
