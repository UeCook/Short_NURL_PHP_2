-- redirect.lua - Short URL redirect handler
-- Looks up short code in su_url shared dict and performs redirect
-- Read path: no disk I/O, pure memory lookup
-- Redirect code (301/302) configurable via nginx $su_redirect_code (default 302)

local M = {}

function M.go()
    local code = ngx.var[1]
    if not code or code == "" then
        ngx.exit(404)
        return
    end

    code = code:lower()

    local su_url = ngx.shared.su_url
    local target = su_url:get(code)

    if type(target) ~= "string" or target == "" then
        ngx.exit(404)
        return
    end

    if not target:lower():match("^https?://") or target:find("[%c%s\"<>]") then
        ngx.log(ngx.ERR, "ShortURL: blocked invalid redirect target for code=", code)
        ngx.exit(404)
        return
    end

    local rc = tonumber(ngx.var.su_redirect_code) or 302
    local allowed_rc = {[301]=true,[302]=true,[303]=true,[307]=true,[308]=true}
    if not allowed_rc[rc] then rc = 302 end
    ngx.redirect(target, rc)
end

return M
