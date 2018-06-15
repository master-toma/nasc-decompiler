**For the leaked GE & AdvExt GE.**

Copy `ai.obj` to current folder & run:

```bash
$ php core/main.php
...
```

## Example

Code:

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

Decompiled source:

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
