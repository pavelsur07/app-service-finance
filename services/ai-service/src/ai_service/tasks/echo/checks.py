from ai_service.tasks.echo.schemas import EchoInput, EchoOutput
from ai_service.verification.checks import CheckContext, CheckResult, Severity


def echo_matches_input(inp: EchoInput, out: EchoOutput, _ctx: CheckContext) -> CheckResult:
    passed = out.text == inp.text
    return CheckResult("echo_matches_input", passed, Severity.BLOCK, "" if passed else "output differs from input")
