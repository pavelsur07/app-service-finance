// Package boundary_test — гейт ядра: fetchkit не знает о конкретных
// маркетплейсах. Провайдерская специфика — в сервисе (адаптер, обёртки).
package boundary_test

import (
	"go/ast"
	"go/parser"
	"go/token"
	"io/fs"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"testing"
)

// providerLiteral — строковый литерал с именем маркетплейса или ресурса
// провайдера («ozon», «wb.», «wildberries»). Комментарии не проверяются.
var providerLiteral = regexp.MustCompile(`(?i)ozon|wildberries|\bwb[.\-]`)

const module = "github.com/pavelsur07/app-service-finance/services/shared/fetchkit"

func TestCoreHasNoProviderSpecifics(t *testing.T) {
	root := filepath.Join("..", "..")
	fset := token.NewFileSet()
	checked := 0
	err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() || !strings.HasSuffix(path, ".go") || strings.HasSuffix(path, "_test.go") {
			return err
		}
		file, err := parser.ParseFile(fset, path, nil, parser.SkipObjectResolution)
		if err != nil {
			return err
		}
		checked++
		for _, imp := range file.Imports {
			p, _ := strconv.Unquote(imp.Path.Value)
			if strings.Contains(p, "app-service-finance/services/") && !strings.HasPrefix(p, module) {
				t.Errorf("%s: импорт сервиса %q — ядро не зависит от сервисов", path, p)
			}
		}
		ast.Inspect(file, func(n ast.Node) bool {
			lit, ok := n.(*ast.BasicLit)
			if ok && lit.Kind == token.STRING && providerLiteral.MatchString(lit.Value) {
				if _, isImport := importValues(file)[lit.Value]; !isImport {
					t.Errorf("%s: литерал провайдера %s — вынести в сервис", fset.Position(lit.Pos()), lit.Value)
				}
			}
			return true
		})
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
	if checked < 10 {
		t.Fatalf("проверено %d файлов — путь к ядру сломан", checked)
	}
}

func importValues(file *ast.File) map[string]struct{} {
	out := make(map[string]struct{}, len(file.Imports))
	for _, imp := range file.Imports {
		out[imp.Path.Value] = struct{}{}
	}
	return out
}
