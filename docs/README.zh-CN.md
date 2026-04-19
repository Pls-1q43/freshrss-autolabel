# FreshRSS AutoLabel

[English](../README.md) | [中文](./README.zh-CN.md) | [Français](./README.fr.md)

`AutoLabel` 是一个 FreshRSS `system` 扩展，用于根据文章内容自动给文章打上**已有的 FreshRSS 标签**。它支持两种识别方式：

- LLM 分类
- Embedding 零样本相似度分类

它采用混合权限模型：

- 管理员负责管理模型档案
- 普通用户基于管理员批准的模型档案创建自己的 AutoLabel 规则

## 功能概览

- 管理员管理模型档案
- 用户管理个人 AutoLabel 规则
- 支持 OpenAI、Anthropic、Gemini、Ollama 的 LLM 分类
- 支持 OpenAI、Gemini、Ollama 的 Embedding 分类
- 每位用户可创建多条 AutoLabel
- 每条 AutoLabel 可关联多个已存在标签
- 新文章与回填任务统一走异步队列
- 若 PHP 提供 `curl_multi`，每个模型档案支持并发窗口处理
- 内置英文、简体中文、法文界面翻译

## 架构说明

- 扩展类型：`system`
- 管理员侧：
  - 创建模型档案
  - 配置 Provider、模型、模式、并发窗口与请求默认值
- 用户侧：
  - 从启用中的模型档案里创建 AutoLabel
  - 选择一个或多个 FreshRSS 已有标签
  - 配置 Prompt、锚定文本、阈值和 instruction
- 队列侧：
  - 新文章入库时自动入队
  - 用户维护任务会自动尝试消费队列
  - 也支持管理员单独调度专用 queue worker

## 支持的 Provider

| Provider | LLM | Embedding |
| --- | --- | --- |
| OpenAI | 支持 | 支持 |
| Anthropic | 支持 | 不支持 |
| Gemini | 支持 | 支持 |
| Ollama | 支持 | 支持 |

说明：

- Anthropic 模型档案只能用于 LLM 模式。
- 并发窗口依赖 PHP `curl` 扩展中的 `curl_multi` 能力。
- 若并发不可用，界面会明确提示。

## 安装与升级

1. 将本仓库放入 FreshRSS 的 `extensions/` 目录。
2. 实际部署目录名应为：

```text
xExtension-AutoLabel
```

3. 在 FreshRSS 扩展页面启用 `AutoLabel`。
4. 进入 `AutoLabel` 控制台完成管理员与用户侧配置。

升级时建议：

- 先备份当前扩展目录
- 使用新版本覆盖扩展文件
- 重启 PHP-FPM / Web 服务或容器
- 刷新页面并检查队列和权限行为

## 配置说明

### 管理员配置

- 模式：LLM 或 Embedding
- Provider
- 模型名
- Base URL
- API Key
- 超时
- 最大内容长度
- 并发窗口大小
- Embedding 维度
- Embedding `num_ctx`
- 默认 instruction

其中 `batch_size` 的语义是**并发窗口大小**，不是串行批次数。比如填 `5` 表示同一个模型档案会同时向 Provider 发起最多 5 条文章请求，等这一组全部结束后再进入下一组。

### 用户配置

- 规则名称
- 目标标签（必须是 FreshRSS 已存在标签）
- 模型档案
- 模式
- Prompt 或 Embedding 锚定文本
- 相似度阈值
- instruction

## 队列与 Worker

AutoLabel 对新文章和回填都使用异步队列。

- 自动消费：
  - 依赖 FreshRSS 的 `FreshrssUserMaintenance`
- 手动消费：
  - 在 AutoLabel 控制台中触发
- 独立消费：
  - 管理员可以使用专用 queue worker URL 配置额外调度

如果你发现队列持续积压，应优先检查：

- FreshRSS 用户维护任务是否实际运行
- 是否启用了并发窗口
- Provider 响应速度是否低于新增速度

## 权限模型

- 未登录用户不能访问 AutoLabel 页面
- 管理员可见：
  - 模型档案管理
  - 队列 worker 地址
  - 所有共享配置区域
- 普通已登录用户可见：
  - 自己的 AutoLabel
  - 试运行、回填、队列、诊断区域
- 普通已登录用户不可见：
  - 管理员模型档案配置
  - 带 token 的 worker 地址

## 故障排查

- 队列吞吐不足：
  - 检查 FreshRSS maintenance 是否真的触发到扩展
- 看不到并发：
  - 检查 PHP 是否启用了 `curl_multi`
- Ollama Embedding 超时：
  - 联合检查 `content_max_chars`、`timeout_seconds`、`embedding_num_ctx` 与 Ollama 日志
- 标签未生效：
  - 先确认目标标签已经在 FreshRSS 中存在

## 发布包

发布包应解压为：

```text
xExtension-AutoLabel/
```

发布辅助脚本：

- [`../scripts/release-audit.sh`](../scripts/release-audit.sh)
- [`../scripts/package-release.sh`](../scripts/package-release.sh)

发布前安全审阅见：

- [`../SECURITY_REVIEW.md`](../SECURITY_REVIEW.md)
- [`../RELEASE.md`](../RELEASE.md)

