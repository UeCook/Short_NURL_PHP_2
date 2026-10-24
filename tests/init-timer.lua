-- Run from project root: lua tests/init-timer.lua
package.preload['cjson.safe'] = function() return {} end
package.preload['shorturl.util.time'] = function() return {} end
package.preload['shorturl.expire_sweep'] = function() return {run = function() end} end
package.preload['shorturl.config'] = function() return {expire_interval = 3600} end
local values = {inited = 1, perm_path = '/perm', temp_path = '/temp'}
local calls, fail = 0, false
ngx = {
    shared = {su_meta = {
        get = function(_, key) return values[key] end,
        set = function(_, key, value) values[key] = value end,
    }},
    var = {su_perm_path = '/perm', su_temp_path = '/temp', su_expire_interval = '60'},
    timer = {every = function(interval)
        assert(interval == 60)
        calls = calls + 1
        if fail then return nil, 'injected failure' end
        return true
    end},
    log = function() end, ERR = 1, NOTICE = 2,
}
local init = dofile('backend/lua/shorturl/init.lua')
assert(init.init() and calls == 1)
assert(init.init() and calls == 1) -- no duplicate timer in this worker
init = dofile('backend/lua/shorturl/init.lua') -- reload: shared inited survives
assert(init.init() and calls == 2)
init = dofile('backend/lua/shorturl/init.lua')
fail = true
assert(init.init() and calls == 3)  -- 注册失败但 init() 仍返回 true（降级模式：下次请求重试）
fail = false
assert(init.init() and calls == 4) -- registration failure is retried
assert(init.init() and calls == 4)
print('lazy timer tests passed')
