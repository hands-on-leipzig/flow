type ConditionOp = '=' | '!=' | '<' | '<=' | '>' | '>='

export type Parameter = {
    id: number
    name: string
    value: string | number | boolean | null
    default_value?: string | number | boolean | null
    type?: string
    context?: string
    level?: number
    first_program?: number | null
    sequence?: number
    program_name?: string
}

export type ParameterCondition = {
    // the parameter affected by the rule
    parameter: number
    // the parameter whose value is checked
    if_parameter: number
    // operator
    is: ConditionOp | string   // keep string to be future-proof if backend adds ops
    // compare-to value (stringly-typed from backend)
    value: string | number | boolean | null
    // action to apply if the condition matches
    action: 'hide' | 'disable'
}
