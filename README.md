# AI decompiler based on heuristic rules

**PHP 7 is required (Windows executables are bundled).**

Supports:

* GF
* AdvExt GF/GE
* HF - thanks to Eressea
* GD - thanks to [@ChaosPaladin](https://github.com/ChaosPaladin)
* C1 - thanks to [@MasterToma](https://github.com/master-toma)
* **Freya is not supported yet**

To run copy `ai.obj` to current folder & run:

```bash
$ php core/main.php
...
```

Or just run `./run.bat` on Windows.

See decompiled code in `ai` directory.

## Command line options

```
--config        Path to JSON configuration file. Default: NULL
--input         Path to AI file to decompile. Default: 'ai.obj'
--output        Path to result file/directory. Default: NULL
--chronicles    AI chronicles. Provide a directory name from the data directory. Default: NULL
--language      Result language. Provide a file name from the core/generators directory (without .php extension). Default: 'nasc'
--tree          Split result in tree structure. Provide a tree depth (0 - don't split, 1 - flat, more than 3 can cause problems on Windows). Default: 3
--join          Join split classes into one file. Provide path to a directory which contains the classes.txt file. Default: NULL
--utf16le       Encode output in UTF-16LE instead of UTF-8. NASC Compiler supports only UTF-16LE. Default: false
--test          Run regression tests. Provide a test file name from the tests directory (without .bin extension). Default: NULL
--generate      Generate regression tests. Provide a new test file name (without extension). Default: NULL
--ignore        Comma-separated list of ignored classes. Default: NULL
```

## Usage examples

Don't split in tree structure:

```
./run.bat --input=gf_off.obj --tree=0
```

Join classes & convert to UTF16-LE for NASC Compiler:

```
./run.bat --join=gf_off --utf16le=1
```

## Decompiled code example

VM code:

```
class 0 hello : (null)

handler 3 23	//  TALKED
	variable_begin
		"i0"
		"talker"
		"myself"
		"_choiceN"
		"_code"
		"_from_choice"
	variable_end

	push_event	//  i0
	push_const 280			//i0
	add
	fetch_i
	branch_false L1
L0
	push_event	//  myself
	push_const 784			//Say
	add
	fetch_i			//Say
S0.	"Hello "
	push_string S0
	push_event	//  talker
	push_const 40			//talker
	add
	fetch_i			//name
	push_const 164			//name
	add
	add_string 1028
	func_call 234946622	//  func[Say]
	shift_sp -1
	shift_sp -1
L1
handler_end

class_end
```

Decompiled code:

```c++
set_compiler_opt base_event_type(@NTYPE_NPC_EVENT)

class hello {
handler:
	EventHandler TALKED(i0, talker) {
		if (i0) {
			Say("Hello " + talker.name);
		}
	}
}
```
