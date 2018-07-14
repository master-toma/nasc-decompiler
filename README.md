**PHP 7 is required.**

**For the leaked GF AI & AdvExt GF/GE AI.**

Copy `ai.obj` to current folder & run:

```bash
$ php core/main.php
...
```

Or just run `./run.bat` on Windows.

See decompiled code in `ai` directory.

## Command Line Arguments

```
--test          Run regression tests. Provide a test file name from the tests directory (without .bin extension). Default: NULL
--generate      Generate regression tests. Provide a new test file name (without extension). Default: NULL
--input         AI file to decompile. Default: 'ai.obj'
--chronicle     AI chronicle. Provide a directory name from the data directory. Default: 'gf'
--language      Resulting language. Provide a file name from the core/generators directory (without .php extension). Default: 'nasc'
--split         Split result by classes. Default: true
```

Example:

```
./run.bat --input=gf_ruoff.obj --test=gf_ruoff
```

## Example

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
